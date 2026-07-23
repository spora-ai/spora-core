<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use DateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Spora\Models\MediaAsset;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\AssetTooLargeException;
use Throwable;

/**
 * Single entry point for ingesting, listing, and deleting archived media.
 *
 * Idempotent on `(tool_call_id, source_url)` so a retry of the same tool
 * call returns the same row instead of duplicating. URL fetch failures
 * downgrade to `external` mode (original URL preserved) so the row
 * survives a CDN outage; byte-mode failures are fatal — the operator
 * asked us to keep the bytes.
 *
 * The URL branch lives in {@see MediaArchiveUrlResolver} so this class
 * stays under the Sonar 20-method threshold.
 */
final class MediaArchiveService
{
    /** Prefix used by every persisted `asset_url`. */
    public const OPAQUE_ASSET_URL_PREFIX = '/api/v1/assets/';

    /**
     * 64 hex chars = 256 bits of entropy — unguessable even for a row
     * referenced by an attacker-known UUID.
     */
    public static function mintPublicAccessToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Returns null for unknown mimes — caller omits the extension
     * entirely rather than fabricating a guess that could mislead browsers.
     */
    public static function extensionForMime(?string $mime): ?string
    {
        if (!is_string($mime) || $mime === '') {
            return null;
        }
        $map = [
            'audio/mpeg'       => 'mp3',
            'audio/mp3'        => 'mp3',
            'audio/wav'        => 'wav',
            'audio/x-wav'      => 'wav',
            'audio/ogg'        => 'ogg',
            'audio/mp4'        => 'm4a',
            'audio/x-m4a'      => 'm4a',
            'audio/flac'       => 'flac',
            'video/mp4'        => 'mp4',
            'video/webm'       => 'webm',
            'video/quicktime'  => 'mov',
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/gif'        => 'gif',
            'image/webp'       => 'webp',
            'image/svg+xml'    => 'svg',
            'application/pdf'  => 'pdf',
            'text/plain'       => 'txt',
        ];
        return $map[strtolower($mime)] ?? null;
    }

    /**
     * Reverse of {@see extensionForMime()}: maps a file extension to its
     * canonical MIME type. Returns null when the extension is not in the
     * static map. Used by `MediaAllowedTypesService` to convert configured
     * image extensions into the MIME list the frontend picker renders.
     */
    public static function mimeForExtension(?string $ext): ?string
    {
        if (!is_string($ext) || $ext === '') {
            return null;
        }
        $reverse = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
        ];
        return $reverse[strtolower(ltrim($ext, '.'))] ?? null;
    }

    public function __construct(
        private readonly AssetStore $assetStore,
        private readonly MediaArchiveUrlResolver $urlResolver,
        private readonly MimeSniffer $sniffer,
        private readonly MetadataExtractor $metadata,
        private readonly MediaConverterRegistry $converters,
        private readonly MediaIngestDecoder $decoder,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Ingest a single asset. Returns the persisted row (existing row
     * returned unchanged when a row with the same `tool_call_id` and
     * `source_url` already exists).
     *
     * @throws InvalidArgumentException When `hex`/`base64` decoding fails.
     */
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

    public function list(ListMediaQuery $query): LengthAwarePaginator
    {
        /** @var Builder<MediaAsset> $builder */
        $builder = MediaAsset::query();

        if ($query->mediaTypes !== null && $query->mediaTypes !== []) {
            $builder->whereIn(
                'media_type',
                array_map(static fn(MediaType $t): string => $t->value, $query->mediaTypes),
            );
        } elseif ($query->mediaType !== null) {
            $builder->where('media_type', $query->mediaType->value);
        }
        if ($query->agentId !== null) {
            $builder->where('agent_id', $query->agentId);
        }
        if ($query->userId !== null) {
            $builder->where('user_id', $query->userId);
        }
        if ($query->pluginSlug !== null) {
            $builder->where('plugin_slug', $query->pluginSlug);
        }
        if ($query->toolName !== null) {
            $builder->where('tool_name', $query->toolName);
        }
        if ($query->uploadSource !== null) {
            // Restricted to the upload-source column on the migration's
            // `media_assets_upload_source_created_at_idx` index — see
            // migration 0056. Filter is a single equality, so the planner
            // uses the leading column of the index.
            $builder->where('upload_source', $query->uploadSource);
        }
        if ($query->from !== null) {
            $builder->where('created_at', '>=', Carbon::instance(DateTime::createFromInterface($query->from)));
        }
        if ($query->to !== null) {
            $builder->where('created_at', '<=', Carbon::instance(DateTime::createFromInterface($query->to)));
        }
        if ($query->search !== null && trim($query->search) !== '') {
            $term = '%' . trim($query->search) . '%';
            // Escape LIKE wildcards so user-typed terms do not act as SQL
            // patterns; the substring match itself stays functional.
            $escaped = addcslashes(trim($query->search), '%_\\');
            $prefixed = '%' . $escaped . '%';
            $builder->where(function (Builder $q) use ($term, $prefixed): void {
                $q->where('prompt', 'like', $term)
                    ->orWhere('filename', 'like', $term)
                    ->orWhere('asset_url', 'like', $term)
                    ->orWhere('source_url', 'like', $term)
                    // Substring UUID match. `id` is a 36-char UUID column;
                    // a full UUID gives an exact match, a prefix/suffix
                    // gives a partial LIKE match. The leading-wildcard
                    // query is fine for a 36-char keyspace and avoids
                    // a separate exact-match fast path.
                    ->orWhere('id', 'like', $prefixed);
            });
        }

        $sort = in_array($query->sort, ListMediaQuery::ALLOWED_SORTS, true)
            ? $query->sort
            : ListMediaQuery::SORT_CREATED_DESC;
        match ($sort) {
            ListMediaQuery::SORT_CREATED_ASC => $builder->orderBy('created_at', 'asc'),
            ListMediaQuery::SORT_SIZE_DESC => $builder->orderBy('byte_size', 'desc'),
            default => $builder->orderBy('created_at', 'desc'),
        };

        return $builder->paginate(
            perPage: $query->perPage(),
            page: $query->page(),
        );
    }

    public function find(string $id): ?MediaAsset
    {
        return MediaAsset::query()->find($id);
    }

    public function delete(string $id): void
    {
        $asset = MediaAsset::query()->find($id);
        if ($asset === null) {
            return;
        }
        $asset->delete();
    }

    public function countForAgent(int $agentId): int
    {
        return MediaAsset::query()->where('agent_id', $agentId)->count();
    }

    /** Body of {@see ingest()} for the non-idempotent case. */
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

    public function writePayloadToAsset(MediaAsset $asset, string $bytes): void
    {
        $asset->payload = $bytes;
        $asset->save();
    }

    private function storeAsset(string $bytes, string $mime, ?string $filename): AssetReference
    {
        try {
            return $this->assetStore->store($bytes, $mime, $filename);
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
            'filename' => $fields->filename ?? $existing->filename,
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
        $asset->prompt = $request->prompt;
        $asset->source_url = $fields->sourceUrl;
        $asset->storage_mode = $fields->storageMode;
        // Always materialize the opaque form. The resolver's $fields->assetUrl
        // is internal routing metadata (CDN URL or pre-refactor token URL)
        // and never leaks to chat bubbles / LLM context.
        $ext  = self::extensionForMime($fields->sniffedMime);
        $asset->asset_url = self::OPAQUE_ASSET_URL_PREFIX . $asset->id . ($ext !== null ? '.' . $ext : '');

        // Probe the schema so pre-#137 fixtures and partial migrations
        // (no user_id, no asset_token, etc.) keep working. New columns
        // land here as the schema grows.
        $table = $asset->getTable();
        $schema = $asset->getConnection()->getSchemaBuilder();
        $optionalFields = [
            'user_id'            => fn() => $fields->userId ?? $request->userId,
            'filename'           => fn() => $fields->filename,
            'upload_source'      => fn() => $fields->uploadSource ?: 'tool',
            'tags'               => fn() => $request->tags,
            'metadata'           => fn() => $request->metadata,
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
            $asset->markdown_content = $markdown;
            $asset->save();
        }
    }

    private function shouldConvert(MediaAsset $asset): bool
    {
        return ($asset->markdown_content === null || $asset->markdown_content === '')
            && $asset->mime_type !== null
            && $asset->mime_type !== '';
    }

    /**
     * Generate a UUIDv4 string without the `ramsey/uuid` dependency —
     * Spora's lightweight footprint doesn't include Ramsey, so we
     * format the bytes directly. Output matches the canonical
     * `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx` shape (y ∈ {8,9,a,b}).
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
