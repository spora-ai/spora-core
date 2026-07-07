<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Psr\Log\LoggerInterface;

/**
 * Owns the URL branch of the Media Archive ingest pipeline.
 *
 * Two responsibilities, kept off {@see MediaArchiveService} so the
 * service's class-level method count stays under Sonar's 20-method
 * threshold:
 *   1. Decide between local/external storage for a URL input and fetch
 *      the body when local mode is selected. HEAD-probes first to skip
 *      known-oversize bodies without paying for the GET.
 *   2. Resolve a MIME for external rows (URL extension → HEAD probe →
 *      caller hint → `application/octet-stream`).
 *
 * Bytes / hex / base64 inputs don't touch this class — they're handled
 * inline by {@see MediaArchiveService} via the inline-decoding helper.
 */
final class MediaArchiveUrlResolver
{
    public function __construct(
        private readonly RemoteMediaFetcher $fetcher,
        private readonly MimeSniffer $sniffer,
        private readonly LoggerInterface $logger,
        private readonly bool $promoteExternal = true,
        private readonly int $maxPromoteBytes = 100 * 1024 * 1024,
    ) {}

    /**
     * Decide local vs external for `$url` and fetch the body when local
     * mode is selected. Returns `[bytes, effectiveUrl]`:
     *   - bytes = null, effectiveUrl = $url    → external fallback
     *   - bytes = string, effectiveUrl = $url  → local promotion
     *
     * @return array{0: ?string, 1: string}
     */
    public function resolve(string $url): array
    {
        if (!$this->promoteExternal) {
            return [null, $url];
        }

        if ($this->headSaysTooLarge($url)) {
            return [null, $url];
        }

        return $this->fetchOrFallback($url);
    }

    /**
     * Resolve a MIME for external rows (no bytes available). Cheapest-first:
     *   1. URL extension sniff (no I/O). Short-circuits on a recognised
     *      extension — false positives are recoverable downstream.
     *   2. HEAD probe's Content-Type.
     *   3. The caller's `mime` hint.
     *   4. `application/octet-stream` as the last-resort fallback.
     */
    public function sniffForExternal(MediaIngestRequest $request, string $url): string
    {
        $sniffed = $this->sniffer->sniffFromExtension($url);
        if ($sniffed !== MimeSniffer::OCTET_STREAM) {
            return $sniffed;
        }

        return $this->probeOrHint($request, $url, $sniffed);
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
}
