<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use DateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
        // Idempotency: short-circuit when a row for this tool call + URL
        // already exists. `asset_url` is what becomes the canonical URL on
        // the row — for the URL branch it's the source URL until promotion,
        // then the local /api/v1/assets/... URL after.
        if ($request->url !== null && $request->toolCallId !== null) {
            $existing = MediaAsset::query()
                ->where('tool_call_id', $request->toolCallId)
                ->where('asset_url', $request->url)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

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
            request: $request,
            assetUrl: $reference->url,
            sourceUrl: $request->url,
            storageMode: $reference->mode,
            sniffedMime: $finalMime,
            mediaType: $mediaType,
            byteSize: strlen($bytes),
            width: $extracted->width ?? $request->width,
            height: $extracted->height ?? $request->height,
            duration: $extracted->durationSeconds ?? $request->durationSeconds,
        );
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

    /**
     * @return array{0: ?string, 1: ?string}  [bytes, effectiveUrl]
     */
    private function resolveBytesAndSourceUrl(MediaIngestRequest $request): array
    {
        if ($request->bytes !== null) {
            return [$request->bytes, null];
        }
        if ($request->hex !== null) {
            if (strlen($request->hex) % 2 !== 0) {
                throw new InvalidArgumentException('Hex payload has odd length.');
            }
            $decoded = @hex2bin($request->hex);
            if ($decoded === false) {
                throw new InvalidArgumentException('Hex payload is not valid hex.');
            }
            return [$decoded, null];
        }
        if ($request->base64 !== null) {
            $decoded = base64_decode($request->base64, strict: true);
            if ($decoded === false) {
                throw new InvalidArgumentException('Base64 payload is not valid base64.');
            }
            return [$decoded, null];
        }

        // URL branch — decide between local/external before fetching.
        $url = $request->url;
        assert($url !== null);

        if (!$this->promoteExternal) {
            return [null, $url];
        }

        // Probe first; if the head says it's too large, skip the body
        // fetch entirely.
        $probe = $this->fetcher->probe($url);
        if ($probe['httpStatus'] >= 200 && $probe['httpStatus'] < 300
            && $probe['contentLength'] !== null
            && $probe['contentLength'] > $this->maxPromoteBytes
        ) {
            $this->logger->info('MediaArchiveService: skipping fetch; content-length exceeds max_promote_bytes', [
                'url' => $url,
                'content_length' => $probe['contentLength'],
                'max_promote_bytes' => $this->maxPromoteBytes,
            ]);
            return [null, $url];
        }

        try {
            $fetched = $this->fetcher->fetch($url);
        } catch (RemoteMediaFetchException $e) {
            // CDN down / 404 / oversized body — keep the row as `external`
            // so the operator still has the metadata to act on.
            $this->logger->warning('MediaArchiveService: fetch failed, falling back to external storage', [
                'url'    => $url,
                'status' => $e->httpStatus,
                'error'  => $e->getMessage(),
            ]);
            return [null, $url];
        }

        return [$fetched['bytes'], $url];
    }

    private function persistExternal(MediaIngestRequest $request, string $url): MediaAsset
    {
        // For external rows, MIME comes from the caller's hint, the HEAD
        // probe's Content-Type, or the URL extension sniff — last
        // because URL paths can lie (e.g. a /image endpoint returning JSON).
        $sniffed = $this->sniffer->sniffFromExtension($url);
        if ($sniffed === 'application/octet-stream') {
            $probe = $this->fetcher->probe($url);
            if (is_string($probe['contentType']) && $probe['contentType'] !== '') {
                $sniffed = $probe['contentType'];
            } elseif (is_string($request->mime) && $request->mime !== '') {
                $sniffed = $request->mime;
            }
        }
        $mediaType = $request->mediaType ?? MediaType::fromMime($sniffed);

        return $this->persist(
            request: $request,
            assetUrl: $url,
            sourceUrl: $url,
            storageMode: 'external',
            sniffedMime: $sniffed,
            mediaType: $mediaType,
            byteSize: $request->byteSize,
            width: $request->width,
            height: $request->height,
            duration: $request->durationSeconds,
        );
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
            throw new RuntimeException(
                'MediaArchiveService: AssetStore refused the payload: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function persist(
        MediaIngestRequest $request,
        string $assetUrl,
        ?string $sourceUrl,
        string $storageMode,
        string $sniffedMime,
        MediaType $mediaType,
        ?int $byteSize,
        ?int $width,
        ?int $height,
        ?float $duration,
    ): MediaAsset {
        // Idempotent upsert keyed on (tool_call_id, asset_url) — when the
        // unique index matches, update the mutable fields instead of
        // inserting a duplicate.
        if ($request->toolCallId !== null) {
            $existing = MediaAsset::query()
                ->where('tool_call_id', $request->toolCallId)
                ->where('asset_url', $assetUrl)
                ->first();
            if ($existing !== null) {
                $existing->fill([
                    'mime_type' => $sniffedMime,
                    'media_type' => $mediaType->value,
                    'byte_size' => $byteSize,
                    'width' => $width,
                    'height' => $height,
                    'duration_seconds' => $duration,
                    'storage_mode' => $storageMode,
                ]);
                $existing->save();
                return $existing;
            }
        }

        $asset = new MediaAsset();
        $asset->id = self::generateUuid();
        $asset->agent_id = $request->agentId;
        $asset->task_id = $request->taskId;
        $asset->tool_call_id = $request->toolCallId;
        $asset->plugin_slug = $request->pluginSlug;
        $asset->tool_name = $request->toolName;
        $asset->media_type = $mediaType->value;
        $asset->mime_type = $sniffedMime;
        $asset->byte_size = $byteSize;
        $asset->width = $width;
        $asset->height = $height;
        $asset->duration_seconds = $duration;
        $asset->prompt = $request->prompt;
        $asset->tags = $request->tags;
        $asset->metadata = $request->metadata;
        $asset->asset_url = $assetUrl;
        $asset->source_url = $sourceUrl;
        $asset->storage_mode = $storageMode;
        $asset->save();

        return $asset;
    }
}
