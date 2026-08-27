<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Psr\Log\LoggerInterface;
use Spora\Models\MediaAsset;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\AssetTooLargeException;
use Spora\Services\Text\Utf8Sanitizer;
use Throwable;

/**
 * Owns the Media Archive ingest pipeline.
 *
 * Pulled out of {@see MediaArchiveService} so the service stays under
 * the Sonar S1448 20-method cap. Public API of the service is unchanged:
 * `MediaArchiveService::ingest()` and `MediaArchiveService::runConversionPipeline()`
 * delegate here, so callers (controllers, tests) don't have to know.
 *
 * The ingest pipeline is the largest "shape" the service carries — it
 * owns the URL → bytes → persist → run-converter chain, plus the
 * `findExisting` / `applyFieldsToExisting` / `insertNew` upsert path.
 * Keeping it in a sibling class lets {@see MediaArchiveService} focus
 * on the listing + ownership concerns the dashboard exposes.
 */
final class MediaArchiveIngestPipeline
{
    public function __construct(
        private readonly MediaIngestDecoder $decoder,
        private readonly MediaArchiveUrlResolver $urlResolver,
        private readonly MimeSniffer $sniffer,
        private readonly MetadataExtractor $metadata,
        private readonly AssetStore $assetStore,
        private readonly MediaConverterRegistry $converters,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function ingest(MediaIngestRequest $request): MediaAsset
    {
        if ($request->toolCallId !== null && $request->url !== null) {
            $existing = $this->findExisting($request->toolCallId, $request->url);
            if ($existing !== null) {
                return $existing;
            }
        }
        return $this->ingestFresh($request);
    }

    public function writePayloadToAsset(MediaAsset $asset, string $bytes): void
    {
        $asset->payload = $bytes;
        $asset->save();
    }

    /**
     * Best-effort converter invocation. A throw is logged and swallowed
     * so a corrupt PDF or unsupported variant doesn't fail the upload.
     * Skipped when markdown_content is already populated to keep re-ingest
     * idempotent.
     */
    public function runConversionPipeline(MediaAsset $asset, string $bytes): void
    {
        if (!$this->shouldConvert($asset)) {
            return;
        }
        try {
            $markdown = $this->converters->convert($bytes, $asset->mime_type, $asset->filename);
        } catch (Throwable $e) {
            $this->logger?->warning('MediaArchiveService: converter failed', [
                'asset_id' => $asset->id,
                'mime'     => $asset->mime_type,
                'error'    => $e->getMessage(),
            ]);
            return;
        }
        if ($markdown !== null) {
            $asset->markdown_content = Utf8Sanitizer::scrubString($markdown);
            $asset->save();
        }
    }

    private function shouldConvert(MediaAsset $asset): bool
    {
        return ($asset->markdown_content === null || $asset->markdown_content === '')
            && $asset->mime_type !== null
            && $asset->mime_type !== '';
    }

    private function ingestFresh(MediaIngestRequest $request): MediaAsset
    {
        $inline = $this->decoder->decodeInline($request);
        if ($inline !== null) {
            return $this->ingestFromBytes($request, $inline, null);
        }

        $url = $request->url;
        assert($url !== null);

        [$bytes, $effectiveUrl] = $this->urlResolver->resolve($url);
        if ($bytes === null) {
            $sniffed = $this->urlResolver->sniffForExternal($request, $effectiveUrl);
            $mediaType = $request->mediaType ?? MediaType::fromMime($sniffed);

            return $this->persist(
                $request,
                new PersistedAssetFields(
                    assetUrl: $effectiveUrl,
                    sourceUrl: $url,
                    storageMode: 'external',
                    sniffedMime: $sniffed,
                    mediaType: $mediaType,
                    byteSize: $request->byteSize,
                    width: $request->width,
                    height: $request->height,
                    durationSeconds: $request->durationSeconds,
                    filename: $request->filename,
                    userId: $request->userId,
                    uploadSource: $request->uploadSource,
                ),
            );
        }

        return $this->ingestFromBytes($request, $bytes, $url);
    }

    /**
     * Write the BLOB AFTER the row UUID is allocated so the opaque
     * `/api/v1/assets/<uuid>` URL is in place when the bytes land —
     * concurrent readers never see a row with a URL that resolves to an
     * empty/corrupt payload.
     */
    private function ingestFromBytes(MediaIngestRequest $request, string $bytes, ?string $sourceUrl): MediaAsset
    {
        $sniffed = $this->sniffer->sniffFromBytes($bytes);
        $mediaType = $request->mediaType ?? MediaType::fromMime($sniffed);
        $extracted = $this->metadata->extract($bytes, $sniffed, $mediaType);

        // `getimagesize` can correct `finfo`'s guess when the caller labelled
        // the asset as an image.
        $finalMime = $extracted->mime !== null && $extracted->mime !== '' ? $extracted->mime : $sniffed;

        $reference = $this->storeAsset($bytes, $finalMime, $request->filename);

        $asset = $this->persist(
            $request,
            new PersistedAssetFields(
                assetUrl: $reference->url,
                sourceUrl: $sourceUrl,
                storageMode: $reference->mode,
                sniffedMime: $finalMime,
                mediaType: $mediaType,
                byteSize: strlen($bytes),
                width: $extracted->width ?? $request->width,
                height: $extracted->height ?? $request->height,
                durationSeconds: $extracted->durationSeconds ?? $request->durationSeconds,
                token: $reference->token,
                filename: $request->filename,
                userId: $request->userId,
                uploadSource: $request->uploadSource,
            ),
        );

        if ($reference->mode === 'data_url') {
            $this->writePayloadToAsset($asset, $bytes);
        }

        // Best-effort: converter throws don't roll back the upload.
        if ($reference->mode !== 'external') {
            $this->runConversionPipeline($asset, $bytes);
        }

        return $asset;
    }

    private function storeAsset(string $bytes, string $mime, ?string $filename): AssetReference
    {
        try {
            $reference = $this->assetStore->store($bytes, $mime, $filename);
        } catch (AssetTooLargeException $e) {
            // Fatal: the operator asked us to keep bytes, so a rejection
            // from the asset-store ceiling can't silently downgrade to
            // external — the URL-branch policy decision was made upstream.
            throw new MediaArchiveException(
                'MediaArchiveService: AssetStore refused the payload: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        // Defence-in-depth: a misconfigured AssetStore ceiling above
        // DATA_URL_MAX_BYTES would still return a `data:` mode reference
        // here, then truncate in writePayloadToAsset() with SQLSTATE
        // 22001 — the same bug migration 0064 fixes for the default path.
        if ($reference->mode === 'data_url' && strlen($bytes) > MediaArchiveService::DATA_URL_MAX_BYTES) {
            throw new MediaArchiveException(sprintf(
                'MediaArchiveService: data_url mode payload of %d bytes exceeds the %d-byte MEDIUMBLOB ceiling. '
                    . 'Lower asset_store.auto_threshold_bytes below DATA_URL_MAX_BYTES to route larger payloads to LocalAssetStore, or switch asset_store.mode to "local".',
                strlen($bytes),
                MediaArchiveService::DATA_URL_MAX_BYTES,
            ));
        }

        return $reference;
    }

    private function persist(MediaIngestRequest $request, PersistedAssetFields $fields): MediaAsset
    {
        // Idempotent upsert on (tool_call_id, source_url). The dedup key
        // is the upstream CDN URL the caller asked us to archive, not the
        // opaque /api/v1/assets/<uuid> form we rewrite at insert time.
        if ($request->toolCallId !== null && $fields->sourceUrl !== null) {
            $existing = $this->findExisting($request->toolCallId, $fields->sourceUrl);
            if ($existing !== null) {
                $this->applyFieldsToExisting($existing, $fields);
                return $existing;
            }
        }

        return $this->insertNew($request, $fields);
    }

    private function findExisting(int $toolCallId, string $sourceUrl): ?MediaAsset
    {
        // Primary key: (tool_call_id, source_url). Fallback: legacy rows
        // that predate migration 0054 and still have the upstream URL in
        // `asset_url`. The fallback keeps dedup working across a partial
        // deployment of the migration.
        $row = MediaAsset::query()
            ->where('tool_call_id', $toolCallId)
            ->where('source_url', $sourceUrl)
            ->first();

        if ($row === null) {
            $row = MediaAsset::query()
                ->where('tool_call_id', $toolCallId)
                ->where('asset_url', $sourceUrl)
                ->first();
        }

        // Defense-in-depth: rows whose `asset_token` is null (partial
        // migration) get a fresh token so /api/v1/assets/<uuid> resolves.
        if ($row !== null && ($row->asset_token === null || $row->asset_token === '')) {
            $row->asset_token = bin2hex(random_bytes(16));
            $row->save();
        }

        return $row;
    }

    private function applyFieldsToExisting(MediaAsset $existing, PersistedAssetFields $fields): void
    {
        $existing->fill([
            'mime_type' => $fields->sniffedMime,
            'media_type' => $fields->mediaType->value,
            'byte_size' => $fields->byteSize,
            'width' => $fields->width,
            'height' => $fields->height,
            'duration_seconds' => $fields->durationSeconds,
            'storage_mode' => $fields->storageMode,
            'asset_token' => $fields->token ?? $existing->asset_token,
            'filename' => $fields->filename !== null
                ? Utf8Sanitizer::scrubString($fields->filename)
                : $existing->filename,
            'user_id' => $fields->userId ?? $existing->user_id,
            'upload_source' => $fields->uploadSource ?: ($existing->upload_source ?? 'tool'),
        ]);
        $existing->save();
    }

    private function insertNew(MediaIngestRequest $request, PersistedAssetFields $fields): MediaAsset
    {
        $asset = new MediaAsset();
        $asset->id = self::generateUuid();
        $asset->agent_id = $request->agentId;
        $asset->task_id = $request->taskId;
        $asset->tool_call_id = $request->toolCallId;
        $asset->plugin_slug = $request->pluginSlug;
        $asset->tool_name = $request->toolName;
        $asset->media_type = $fields->mediaType->value;
        $asset->mime_type = $fields->sniffedMime;
        $asset->byte_size = $fields->byteSize;
        $asset->width = $fields->width;
        $asset->height = $fields->height;
        $asset->duration_seconds = $fields->durationSeconds;
        $asset->prompt = $request->prompt !== null ? Utf8Sanitizer::scrubString($request->prompt) : null;
        $asset->source_url = $fields->sourceUrl;
        $asset->storage_mode = $fields->storageMode;
        // Always materialize the opaque form. The resolver's $fields->assetUrl
        // is internal routing metadata (CDN URL or pre-refactor token URL)
        // and never leaks to chat bubbles / LLM context.
        $ext  = MediaArchiveService::extensionForMime($fields->sniffedMime);
        $asset->asset_url = MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $asset->id . ($ext !== null ? '.' . $ext : '');

        // Probe the schema so pre-#137 fixtures and partial migrations
        // (no user_id, no asset_token, etc.) keep working. New columns
        // land here as the schema grows.
        $table = $asset->getTable();
        $schema = $asset->getConnection()->getSchemaBuilder();
        $optionalFields = [
            'user_id'            => fn() => $fields->userId ?? $request->userId,
            'filename'           => fn() => $fields->filename !== null ? Utf8Sanitizer::scrubString($fields->filename) : null,
            'upload_source'      => fn() => $fields->uploadSource ?: 'tool',
            'tags'               => fn() => Utf8Sanitizer::scrub($request->tags),
            'metadata'           => fn() => Utf8Sanitizer::scrub($request->metadata),
            // local-mode reuses the resolver's token verbatim so
            // LocalAssetStore::readFromAsset() can find the on-disk file
            // from a UUID lookup; DB-mode mints a fresh token to keep the
            // unique index uniform (the token is opaque in DB mode).
            'asset_token'        => fn() => $fields->token ?? bin2hex(random_bytes(16)),
        ];
        foreach ($optionalFields as $column => $valueFn) {
            if ($schema->hasColumn($table, $column)) {
                $asset->{$column} = $valueFn();
            }
        }
        if ($request->publicAccessToken !== null && $request->publicAccessToken !== ''
            && $schema->hasColumn($table, 'public_access_token')) {
            $asset->public_access_token = $request->publicAccessToken;
        }
        $asset->save();

        return $asset;
    }

    /**
     * Generate a UUIDv4 string without the `ramsey/uuid` dependency —
     * Spora's lightweight footprint doesn't include Ramsey, so we
     * format the bytes directly. Output matches the canonical
     * `xxxxxxxx-xxxx-Mxxx-Nxxx-xxxxxxxxxxxx` layout with M=4 and the
     * variant nibble N in `8|9|a|b`.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
