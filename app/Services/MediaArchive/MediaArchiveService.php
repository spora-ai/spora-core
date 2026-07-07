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

/**
 * The single entry point for ingesting, listing, and deleting archived
 * media. Plugins opt in explicitly via {@see MediaIngestRequest}.
 *
 * **Ingest pipeline (URL is primary):**
 *
 *  1. Probe `Content-Type` / `Content-Length` via HEAD.
 *  2. If `media_archive.max_promote_bytes` would be exceeded, or
 *     `promote_external = false`, store the row as `external`
 *     (the URL becomes `asset_url`; no bytes are fetched).
 *  3. Otherwise GET the body with a configurable timeout and stream
 *     it through {@see AssetStore::store()}.
 *  4. Sniff MIME from the bytes (`finfo` + magic-byte table). The sniff
 *     wins over the caller's hint.
 *  5. Derive image dimensions via `getimagesize()`. Best-effort
 *     `ffprobe` for audio/video (gated on
 *     `media_archive.ffprobe_enabled` + binary on PATH).
 *  6. Persist the row. Idempotent on `(tool_call_id, asset_url)`.
 *
 * For the `bytes` / `hex` / `base64` input forms, the URL branch is
 * skipped — the bytes are processed the same way from step 3 onwards.
 *
 * **Failure modes:**
 *
 *  - `hex` / `base64` decode failure → {@see InvalidArgumentException}.
 *  - URL fetch non-2xx, oversized body, transport failure →
 *    {@see RemoteMediaFetchException}; the service translates this to
 *    `external` mode with the original URL preserved, so the row still
 *    has *some* record even when the CDN goes away. Operators can retry
 *    ingest later if needed.
 *  - {@see AssetStore} refusal after a local-mode decision →
 *    {@see MediaArchiveException} (fatal — see class doc for rationale).
 */
final class MediaArchiveService
{
    public function __construct(
        private readonly AssetStore $assetStore,
        private readonly RemoteMediaFetcher $fetcher,
        private readonly MimeSniffer $sniffer,
        private readonly MetadataExtractor $metadata,
        private readonly LoggerInterface $logger,
        private readonly bool $promoteExternal = true,
        private readonly int $maxPromoteBytes = 100 * 1024 * 1024,
    ) {}

    /**
     * Ingest a single asset. Returns the persisted row (existing row
     * returned unchanged when `(tool_call_id, asset_url)` already exists).
     *
     * @throws InvalidArgumentException When `hex`/`base64` decoding fails.
     */
    public function ingest(MediaIngestRequest $request): MediaAsset
    {
        $existing = $this->findExisting($request);
        if ($existing !== null) {
            return $existing;
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
     * Idempotency: short-circuit when a row for this tool call + URL
     * already exists. `asset_url` is what becomes the canonical URL on
     * the row — for the URL branch it's the source URL until promotion,
     * then the local /api/v1/assets/... URL after.
     */
    private function findExisting(MediaIngestRequest $request): ?MediaAsset
    {
        if ($request->url === null || $request->toolCallId === null) {
            return null;
        }

        return MediaAsset::query()
            ->where('tool_call_id', $request->toolCallId)
            ->where('asset_url', $request->url)
            ->first();
    }

    /**
     * Body of {@see ingest()} for the non-idempotent case. The URL
     * branch delegates to {@see resolveBytesAndSourceUrl()} to decide
     * between local promotion and external fallback; the rest of the
     * pipeline is shared across input forms.
     */
    private function ingestFresh(MediaIngestRequest $request): MediaAsset
    {
        [$bytes, $effectiveUrl] = $this->resolveBytesAndSourceUrl($request);

        if ($bytes === null) {
            // URL branch with external storage — no body to analyse.
            return $this->persistExternal($request, $effectiveUrl);
        }

        $sniffed = $this->sniffer->sniffFromBytes($bytes);
        $mediaType = $request->mediaType ?? MediaType::fromMime($sniffed);
        $extracted = $this->metadata->extract($bytes, $sniffed, $mediaType);

        // `getimagesize` can correct the sniffed MIME; trust that when
        // the caller labelled the asset as an image.
        $finalMime = $extracted->mime !== null && $extracted->mime !== '' ? $extracted->mime : $sniffed;

        $reference = $this->storeAsset($bytes, $finalMime, $request->filename);

        return $this->persist(
            $request,
            new PersistedAssetFields(
                assetUrl: $reference->url,
                sourceUrl: $request->url,
                storageMode: $reference->mode,
                sniffedMime: $finalMime,
                mediaType: $mediaType,
                byteSize: strlen($bytes),
                width: $extracted->width ?? $request->width,
                height: $extracted->height ?? $request->height,
                durationSeconds: $extracted->durationSeconds ?? $request->durationSeconds,
            ),
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}  [bytes, effectiveUrl]
     */
    private function resolveBytesAndSourceUrl(MediaIngestRequest $request): array
    {
        $inline = $this->decodeInlinePayload($request);
        if ($inline !== null) {
            return $inline;
        }

        return $this->resolveUrlPayload($request);
    }

    /**
     * Bytes / hex / base64 input forms are all "the caller already has
     * the bytes — store them as-is". Hex with odd length and any
     * non-strict-decodable base64 raise {@see InvalidArgumentException}
     * so the plugin can surface a meaningful error to the LLM.
     *
     * @return array{0: string, 1: null}|null
     */
    private function decodeInlinePayload(MediaIngestRequest $request): ?array
    {
        $bytes = $this->extractInlineBytes($request);
        if ($bytes === null) {
            return null;
        }

        return [$bytes, null];
    }

    private function extractInlineBytes(MediaIngestRequest $request): ?string
    {
        if ($request->bytes !== null) {
            return $request->bytes;
        }
        return $this->decodeFromStringInputs($request);
    }

    private function decodeFromStringInputs(MediaIngestRequest $request): ?string
    {
        if ($request->hex !== null) {
            return $this->decodeHex($request->hex);
        }
        if ($request->base64 !== null) {
            return $this->decodeBase64($request->base64);
        }

        return null;
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

    /**
     * URL branch — decide between local/external before fetching.
     *
     * @return array{0: ?string, 1: string}  [bytes, effectiveUrl]
     */
    private function resolveUrlPayload(MediaIngestRequest $request): array
    {
        $url = $request->url;
        assert($url !== null);

        if (!$this->promoteExternal) {
            return [null, $url];
        }

        if ($this->headSaysTooLarge($url)) {
            return [null, $url];
        }

        return $this->fetchOrFallback($url);
    }

    /**
     * Probe the URL via HEAD; if the upstream reports a body that
     * exceeds the configured cap, skip the body fetch and stay
     * external. Operators get a meaningful log line rather than a
     * silent fallback.
     */
    private function headSaysTooLarge(string $url): bool
    {
        $probe = $this->fetcher->probe($url);
        $declaredOversize = $probe['httpStatus'] >= 200
            && $probe['httpStatus'] < 300
            && $probe['contentLength'] !== null
            && $probe['contentLength'] > $this->maxPromoteBytes;
        if ($declaredOversize) {
            $this->logger->info('MediaArchiveService: skipping fetch; content-length exceeds max_promote_bytes', [
                'url' => $url,
                'content_length' => $probe['contentLength'],
                'max_promote_bytes' => $this->maxPromoteBytes,
            ]);
        }

        return $declaredOversize;
    }

    /**
     * Try to GET the body; on any fetch exception (non-2xx, oversized
     * body, transport error) keep the row as `external` so the operator
     * still has the metadata to act on. The original URL is preserved
     * on both branches.
     *
     * @return array{0: ?string, 1: string}
     */
    private function fetchOrFallback(string $url): array
    {
        try {
            $fetched = $this->fetcher->fetch($url);
            return [$fetched['bytes'], $url];
        } catch (RemoteMediaFetchException $e) {
            $this->logger->warning('MediaArchiveService: fetch failed, falling back to external storage', [
                'url'    => $url,
                'status' => $e->httpStatus,
                'error'  => $e->getMessage(),
            ]);
            return [null, $url];
        }
    }

    private function persistExternal(MediaIngestRequest $request, string $url): MediaAsset
    {
        $sniffed = $this->sniffForExternal($request, $url);
        $mediaType = $request->mediaType ?? MediaType::fromMime($sniffed);

        return $this->persist(
            $request,
            new PersistedAssetFields(
                assetUrl: $url,
                sourceUrl: $url,
                storageMode: 'external',
                sniffedMime: $sniffed,
                mediaType: $mediaType,
                byteSize: $request->byteSize,
                width: $request->width,
                height: $request->height,
                durationSeconds: $request->durationSeconds,
            ),
        );
    }

    /**
     * For external rows, MIME is resolved cheapest-first:
     *   1. URL extension sniff (no I/O). If the extension is recognised
     *      we trust it and short-circuit — false positives are
     *      recoverable downstream because the ingest pipeline only
     *      classifies, it doesn't gate persistence on MIME.
     *   2. HEAD probe's Content-Type.
     *   3. The caller's `mime` hint.
     *   4. `application/octet-stream` as the last-resort fallback.
     */
    private function sniffForExternal(MediaIngestRequest $request, string $url): string
    {
        $sniffed = $this->sniffer->sniffFromExtension($url);
        if ($sniffed !== MimeSniffer::OCTET_STREAM) {
            return $sniffed;
        }

        return $this->probeOrHint($request, $url, $sniffed);
    }

    private function probeOrHint(MediaIngestRequest $request, string $url, string $fallback): string
    {
        $probe = $this->fetcher->probe($url);
        if (is_string($probe['contentType']) && $probe['contentType'] !== '') {
            return $probe['contentType'];
        }
        if (is_string($request->mime) && $request->mime !== '') {
            return $request->mime;
        }

        return $fallback;
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
        // Idempotent upsert keyed on (tool_call_id, asset_url) — when the
        // unique index matches, update the mutable fields instead of
        // inserting a duplicate.
        if ($request->toolCallId !== null) {
            $existing = $this->findExistingForAsset($request->toolCallId, $fields->assetUrl);
            if ($existing !== null) {
                $this->applyFieldsToExisting($existing, $fields);
                return $existing;
            }
        }

        return $this->insertNew($request, $fields);
    }

    private function findExistingForAsset(int $toolCallId, string $assetUrl): ?MediaAsset
    {
        return MediaAsset::query()
            ->where('tool_call_id', $toolCallId)
            ->where('asset_url', $assetUrl)
            ->first();
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
        $asset->tags = $request->tags;
        $asset->metadata = $request->metadata;
        $asset->asset_url = $fields->assetUrl;
        $asset->source_url = $fields->sourceUrl;
        $asset->storage_mode = $fields->storageMode;
        $asset->save();

        return $asset;
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
