<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Wraps {@see HttpClientInterface} for the Media Archive's CDN-fetch path.
 *
 * Two-stage fetch:
 *   1. HEAD probe to read `Content-Type` and `Content-Length` cheaply.
 *      Either header is optional and may be missing — the body fetch does
 *      not depend on them, they just feed the policy decisions below.
 *   2. Body GET with a configurable timeout. The response is materialised
 *      to a string bounded by `media_archive.max_promote_bytes` (default
 *      100 MiB) — the bytes are then handed to {@see AssetStore::store()}.
 *      This is not a streaming read: peak memory on a max-size body is
 *      roughly the configured cap. Operators who need true streaming for
 *      very large media should keep `promote_external = false` and let
 *      the row stay as `storage_mode=external` with the source URL.
 *
 * Errors are normalised to {@see RemoteMediaFetchException} so the service
 * can decide whether to fall back to `storage_mode=external` or surface
 * the failure to the plugin.
 */
final class RemoteMediaFetcher
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxBytes = 100 * 1024 * 1024,
    ) {}

    /**
     * Fetch the URL and return the body + the headers we cared about.
     * Caller decides what to do with the body (typically: stream into
     * {@see \Spora\Services\AssetStore}).
     *
     * @return array{bytes: string, contentType: ?string, contentLength: ?int}
     *
     * @throws RemoteMediaFetchException
     */
    public function fetch(string $url): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'timeout'      => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
                'headers'      => ['Accept' => '*/*'],
            ]);
            $status = $response->getStatusCode();
        } catch (Throwable $e) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: 0,
                message: 'Remote media fetch transport error: ' . $e->getMessage(),
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: $status,
                message: sprintf('Remote media fetch failed with HTTP %d for %s', $status, $url),
            );
        }

        try {
            $headers = $response->getHeaders(false);
        } catch (Throwable $e) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: 0,
                message: 'Remote media fetch header read failed: ' . $e->getMessage(),
            );
        }

        $contentLengthRaw = $headers['content-length'] ?? null;
        $contentLength = is_array($contentLengthRaw) && isset($contentLengthRaw[0])
            ? (int) $contentLengthRaw[0]
            : null;

        if ($contentLength !== null && $contentLength > $this->maxBytes) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: 413,
                message: sprintf(
                    'Remote media exceeds max_promote_bytes (%d > %d); refusing to fetch body for %s',
                    $contentLength,
                    $this->maxBytes,
                    $url,
                ),
            );
        }

        $contentTypeRaw = $headers['content-type'] ?? null;
        $contentType = is_array($contentTypeRaw) && isset($contentTypeRaw[0])
            ? trim(explode(';', $contentTypeRaw[0])[0])
            : null;

        try {
            $bytes = $response->getContent();
        } catch (Throwable $e) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: 0,
                message: 'Remote media fetch transport error: ' . $e->getMessage(),
            );
        }

        if (strlen($bytes) > $this->maxBytes) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: 413,
                message: sprintf(
                    'Remote media body exceeds max_promote_bytes (%d > %d); refusing to keep %s',
                    strlen($bytes),
                    $this->maxBytes,
                    $url,
                ),
            );
        }

        $this->logger->debug('RemoteMediaFetcher: fetched bytes', [
            'url'        => $url,
            'bytes'      => strlen($bytes),
            'mime'       => $contentType,
        ]);

        return [
            'bytes'         => $bytes,
            'contentType'   => $contentType !== '' ? $contentType : null,
            'contentLength' => $contentLength,
        ];
    }

    /**
     * Lightweight HEAD probe — only the headers we care about. Returns
     * `null` for any field the server didn't send. Used by the service
     * to decide between `local` and `external` storage mode before
     * committing to a body fetch.
     *
     * @return array{contentType: ?string, contentLength: ?int, httpStatus: int}
     */
    public function probe(string $url): array
    {
        try {
            $response = $this->http->request('HEAD', $url, [
                'timeout'      => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);

            $contentType = $headers['content-type'] ?? null;
            $contentType = is_array($contentType) && isset($contentType[0])
                ? trim(explode(';', $contentType[0])[0])
                : null;

            $contentLength = $headers['content-length'] ?? null;
            $contentLength = is_array($contentLength) && isset($contentLength[0])
                ? (int) $contentLength[0]
                : null;

            return [
                'contentType'   => $contentType !== '' ? $contentType : null,
                'contentLength' => $contentLength,
                'httpStatus'    => $status,
            ];
        } catch (Throwable $e) {
            $this->logger->warning('RemoteMediaFetcher HEAD probe failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'contentType'   => null,
                'contentLength' => null,
                'httpStatus'    => 0,
            ];
        }
    }
}
