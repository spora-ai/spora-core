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
 * Plugins opt in via {@see MediaIngestRequest}.
 *
 * Ingest pipeline (URL form): HEAD probe → (optional) GET with timeout
 * → {@see AssetStore::store()} → MIME sniff → metadata extract
 * (`getimagesize`, optional `ffprobe`) → persist. Idempotent on
 * `(tool_call_id, source_url)` — the upstream CDN URL the operator
 * supplied, with a legacy `asset_url` fallback for rows predating the
 * opaque-URL refactor (migration 0053). Bytes / hex / base64 inputs
 * skip the URL branch and feed straight into the byte-processing steps.
 *
 * Failures: `hex` / `base64` decode → {@see InvalidArgumentException}.
 * URL fetch non-2xx / oversized / transport → translated to `external`
 * mode with the original URL preserved, so the row still exists when
 * the CDN goes away. AssetStore refusal after local-mode decision →
 * {@see MediaArchiveException} (fatal — the operator asked us to keep
 * the bytes, so a soft downgrade isn't safe).
 *
 * The URL branch lives in {@see MediaArchiveUrlResolver} so this
 * orchestrator stays under the 20-method Sonar threshold.
 *
 * Not declared `final` because Mockery needs to construct a named mock
 * for HTTP-handler tests; subclassing is still discouraged — instantiate
 * via PHP-DI.
 */
class MediaArchiveService
{
    /** Prefix used by every persisted `asset_url`. */
    public const OPAQUE_ASSET_URL_PREFIX = '/api/v1/assets/';

    /**
     * Map a MIME type to a file extension used in the public asset URL.
     * Returns null for unknown / null mimes — caller omits the extension
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

    public function __construct(
        private readonly AssetStore $assetStore,
        private readonly MediaArchiveUrlResolver $urlResolver,
        private readonly MimeSniffer $sniffer,
        private readonly MetadataExtractor $metadata,
        private readonly MediaConverterRegistry $converters,
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

        if ($query->mediaType !== null) {
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
        if ($query->from !== null) {
            $builder->where('created_at', '>=', Carbon::instance(DateTime::createFromInterface($query->from)));
        }
        if ($query->to !== null) {
            $builder->where('created_at', '<=', Carbon::instance(DateTime::createFromInterface($query->to)));
        }
        if ($query->search !== null && trim($query->search) !== '') {
            $term = '%' . trim($query->search) . '%';
            $builder->where(function (Builder $q) use ($term): void {
                $q->where('prompt', 'like', $term)
                    ->orWhere('filename', 'like', $term)
                    ->orWhere('asset_url', 'like', $term)
                    ->orWhere('source_url', 'like', $term);
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

    /**
     * Body of {@see ingest()} for the non-idempotent case. Dispatches to
     * the inline decoder or the URL resolver depending on which input
     * form was supplied, then delegates to {@see ingestFromBytes()} for
     * the shared bytes-to-row pipeline.
     */
    private function ingestFresh(MediaIngestRequest $request): MediaAsset
    {
        $inline = $this->decodeInline($request);
        if ($inline !== null) {
            return $this->ingestFromBytes($request, $inline, null);
        }

        $url = $request->url;
        assert($url !== null);

        [$bytes, $effectiveUrl] = $this->urlResolver->resolve($url);
        if ($bytes === null) {
            // URL branch with external storage — no body to analyse.
            $sniffed = $this->urlResolver->sniffForExternal($request, $effectiveUrl);
            $mediaType = $request->mediaType ?? MediaType::fromMime($sniffed);

            $asset = $this->persist(
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
            return $asset;
        }

        return $this->ingestFromBytes($request, $bytes, $url);
    }

    /**
     * Shared bytes-to-row pipeline used by every ingest input form once
     * the bytes are in hand. Sniffs MIME, extracts metadata, stores via
     * AssetStore, persists the row, runs the conversion pipeline, and
     * finally writes the payload (DB BLOB or no-op for local) once the
     * UUID is known — so the opaque URL is in place before any bytes
     * land and concurrent readers never see a half-loaded row.
     */
    private function ingestFromBytes(MediaIngestRequest $request, string $bytes, ?string $sourceUrl): MediaAsset
    {
        $sniffed = $this->sniffer->sniffFromBytes($bytes);
        $mediaType = $request->mediaType ?? MediaType::fromMime($sniffed);
        $extracted = $this->metadata->extract($bytes, $sniffed, $mediaType);

        // `getimagesize` can correct the sniffed MIME when the caller labelled
        // the asset as an image — prefer it over `finfo`'s guess.
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

        // DB mode needs the BLOB written now (Local mode already wrote to
        // disk in `storeAsset()`; External mode owns no bytes).
        if ($reference->mode === 'data_url') {
            $this->writePayloadToAsset($asset, $bytes);
        }

        // Run the conversion pipeline (PDF → markdown, text passthrough, …)
        // against the bytes in hand. Best-effort — failures don't roll back
        // the upload. Skipped for External mode (no bytes).
        if ($reference->mode !== 'external') {
            $this->runConversionPipeline($asset, $bytes);
        }

        return $asset;
    }

    /**
     * Persist the raw payload bytes to a {@see MediaAsset} row's `payload`
     * BLOB column. Called from {@see self::ingestFromBytes()} for DB-mode
     * rows AFTER the row UUID is allocated, so the public
     * `/api/v1/assets/<uuid>` URL is already in place when the bytes land.
     * A concurrent reader can therefore never see a row with a URL that
     * resolves to an empty/corrupt payload.
     */
    public function writePayloadToAsset(MediaAsset $asset, string $bytes): void
    {
        $asset->payload = $bytes;
        $asset->save();
    }

    /**
     * Bytes / hex / base64 input forms are all "the caller already has
     * the bytes — store them as-is". Hex with odd length and any
     * non-strict-decodable base64 raise {@see InvalidArgumentException}
     * so the plugin can surface a meaningful error to the LLM.
     */
    private function decodeInline(MediaIngestRequest $request): ?string
    {
        if ($request->bytes !== null && $request->bytes !== '') {
            $bytes = $request->bytes;
        } elseif ($request->hex !== null && $request->hex !== '') {
            $bytes = $this->decodeHex($request->hex);
        } elseif ($request->base64 !== null && $request->base64 !== '') {
            $bytes = $this->decodeBase64($request->base64);
        } else {
            $bytes = null;
        }

        return $bytes;
    }

    private function decodeHex(string $hex): string
    {
        if (strlen($hex) % 2 !== 0) {
            throw new InvalidArgumentException('Hex payload has odd length.');
        }
        $decoded = @hex2bin($hex);
        if ($decoded === false) {
            throw new InvalidArgumentException('Hex payload is not valid hex.');
        }

        return $decoded;
    }

    private function decodeBase64(string $payload): string
    {
        $decoded = base64_decode($payload, strict: true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Base64 payload is not valid base64.');
        }

        return $decoded;
    }

    private function storeAsset(string $bytes, string $mime, ?string $filename): AssetReference
    {
        try {
            return $this->assetStore->store($bytes, $mime, $filename);
        } catch (AssetTooLargeException $e) {
            // Surface as a hard failure for the local-mode case; the
            // caller asked us to keep bytes, so a rejection from the
            // configured asset store ceiling is fatal — we can't
            // silently downgrade to external here because the URL
            // branch's policy decision was made upstream.
            throw new MediaArchiveException(
                'MediaArchiveService: AssetStore refused the payload: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function persist(MediaIngestRequest $request, PersistedAssetFields $fields): MediaAsset
    {
        // Idempotent upsert keyed on `(tool_call_id, source_url)` (with a
        // legacy `asset_url` fallback inside {@see findExisting()}) — when
        // the lookup hits, update the mutable fields instead of inserting
        // a duplicate. The dedup key is the upstream CDN URL the caller
        // asked us to archive, not the opaque `/api/v1/assets/<uuid>`
        // form we rewrite at insert time.
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
        // Idempotent re-ingest: the canonical dedup key is
        // `(tool_call_id, source_url)` after asset_url was rewritten to
        // the opaque UUID form (see migration 0054's index). For rows
        // that predate the refactor — where `asset_url` still holds the
        // upstream URL — fall back to that lookup so a partial
        // deployment doesn't break dedup for legacy rows.
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

        // Defense-in-depth: rows that predate the
        // `migrated_from_inline_data_url` column add (or that snuck
        // in via a DB whose migration only partially applied) can
        // land here with `asset_token` still NULL. Allocate one so
        // `/api/v1/assets/<uuid>` always resolves to a file.
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
        $asset->user_id = $fields->userId ?? $request->userId;
        $asset->plugin_slug = $request->pluginSlug;
        $asset->tool_name = $request->toolName;
        $asset->media_type = $fields->mediaType->value;
        $asset->mime_type = $fields->sniffedMime;
        $asset->byte_size = $fields->byteSize;
        $asset->width = $fields->width;
        $asset->height = $fields->height;
        $asset->duration_seconds = $fields->durationSeconds;
        $asset->prompt = $request->prompt;
        $asset->filename = $fields->filename;
        $asset->upload_source = $fields->uploadSource ?: 'tool';
        $asset->tags = $request->tags;
        $asset->metadata = $request->metadata;
        // Asset URL is always an opaque `/api/v1/assets/<uuid>` form. The
        // `$fields->assetUrl` from the resolver is internal routing
        // metadata (e.g., the upstream CDN URL for external mode, or the
        // pre-refactor token URL); we override it here so chat bubbles
        // and LLM context always see a short URL. The extension is
        // appended when the sniffed mime maps to a known type, so
        // browsers use the right filename on download.
        $ext  = self::extensionForMime($fields->sniffedMime);
        $asset->asset_url = self::OPAQUE_ASSET_URL_PREFIX . $asset->id . ($ext !== null ? '.' . $ext : '');
        $asset->source_url = $fields->sourceUrl;
        $asset->storage_mode = $fields->storageMode;
        // `asset_token` ties the row to its on-disk file (local mode) or
        // is just an opaque correlation id (DB mode). `LocalAssetStore`
        // mints a 32-hex token as the on-disk filename; we reuse that
        // token verbatim so `LocalAssetStore::readFromAsset()` can find
        // the file from a UUID lookup. DB-mode rows get a freshly
        // generated token; it's not load-bearing but keeps the unique
        // index uniform.
        $asset->asset_token = $fields->token ?? bin2hex(random_bytes(16));
        $asset->save();

        return $asset;
    }

    /**
     * Run the {@see MediaConverterRegistry} against an asset and write the
     * extracted markdown into `markdown_content`. Best-effort: a converter
     * throw is logged and swallowed so the upload itself never fails on
     * extraction problems (e.g. a corrupt PDF, an unsupported variant).
     *
     * Skipped when `markdown_content` is already populated — that protects
     * idempotent re-ingest from re-running the pipeline.
     */
    public function runConversionPipeline(MediaAsset $asset, string $bytes): void
    {
        if ($asset->markdown_content !== null && $asset->markdown_content !== '') {
            return;
        }
        if ($asset->mime_type === null || $asset->mime_type === '') {
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
        if ($markdown === null) {
            return; // no converter handles this MIME — leave markdown_content NULL
        }
        $asset->markdown_content = $markdown;
        $asset->save();
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
