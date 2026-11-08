<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Psr\Log\LoggerInterface;
use Spora\Services\SsrfGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Wraps {@see HttpClientInterface} for the Media Archive's CDN-fetch path.
 *
 * Two-stage fetch:
 *   1. HEAD probe to read `Content-Type` and `Content-Length` cheaply.
 *      Either header is optional and may be missing — the body fetch does
 *      not depend on them, they just feed the policy decisions below.
 *   2. Body GET with a configurable timeout. The body is read in chunks
 *      and refused once it passes `media_archive.max_promote_bytes`
 *      (default 100 MiB) — the bytes are then handed to
 *      {@see AssetStore::store()}. This is not a streaming *store*: peak
 *      memory on a max-size body is roughly the configured cap. Operators
 *      who need true streaming for very large media should keep
 *      `promote_external = false` and let the row stay as
 *      `storage_mode=external` with the source URL.
 *
 * Every destination is screened by {@see SsrfGuard}, so a URL that
 * resolves inside the deployment's own network is refused. A redirect is
 * a destination of its own, and the client is told not to follow one:
 * the fetcher follows it itself, screening each hop.
 *
 * Errors are normalised to {@see RemoteMediaFetchException} so the service
 * can decide whether to fall back to `storage_mode=external` or surface
 * the failure to the plugin.
 */
final class RemoteMediaFetcher
{
    /**
     * How many redirects one fetch may follow. Each hop is screened, so
     * the chain is bounded by the hops and not by the server.
     */
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxBytes = 100 * 1024 * 1024,
        private readonly SsrfGuard $ssrf = new SsrfGuard(SsrfGuard::WEB_FETCH_POLICY),
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
        $url = $this->screen($url);

        [$response] = $this->send('GET', $url, ['headers' => ['Accept' => '*/*']]);

        $status = $response->getStatusCode();
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
            $bytes = $this->readBody($response, $url);
        } catch (RemoteMediaFetchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: 0,
                message: 'Remote media fetch transport error: ' . $e->getMessage(),
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
     * The request, following the redirects the server names.
     *
     * The client is told not to follow a redirect itself: the guard
     * screened the URL as given, and a redirect target is a different
     * host. Each hop is screened here before it is requested.
     *
     * @param  array<string, mixed> $options
     * @return array{0: ResponseInterface}
     *
     * @throws RemoteMediaFetchException
     */
    private function send(string $method, string $url, array $options): array
    {
        $hops = 0;

        while (true) {
            try {
                $response = $this->http->request($method, $url, $options + [
                    'timeout'      => $this->timeoutSeconds,
                    'max_duration' => $this->timeoutSeconds,
                    'max_redirects' => 0,
                ]);
                $status = $response->getStatusCode();
            } catch (Throwable $e) {
                throw new RemoteMediaFetchException(
                    url: $url,
                    httpStatus: 0,
                    message: 'Remote media fetch transport error: ' . $e->getMessage(),
                );
            }

            $target = $this->redirectTarget($response, $status);
            if ($target === null) {
                return [$response];
            }

            if (++$hops > self::MAX_REDIRECTS) {
                throw new RemoteMediaFetchException(
                    url: $url,
                    httpStatus: $status,
                    message: sprintf('Remote media fetch exceeded %d redirects for %s', self::MAX_REDIRECTS, $url),
                );
            }

            $url = $this->screen($target);
        }
    }

    /**
     * The URL a redirect response names, or null when the response is not
     * a redirect.
     */
    private function redirectTarget(ResponseInterface $response, int $status): ?string
    {
        if ($status < 300 || $status >= 400) {
            return null;
        }

        $target = $response->getInfo('redirect_url');

        return is_string($target) && $target !== '' ? $target : null;
    }

    /**
     * Refuse a URL the deployment's own network is reachable at.
     *
     * @throws RemoteMediaFetchException
     */
    private function screen(string $url): string
    {
        $reason = $this->ssrf->rejectReason($url);
        if ($reason !== null) {
            throw new RemoteMediaFetchException(
                url: $url,
                httpStatus: 0,
                message: $reason,
            );
        }

        return $url;
    }

    /**
     * The body, read in chunks and refused once it passes the ceiling.
     *
     * The budget is on the decoded bytes: the transport inflates the body
     * before it reaches us, so a compressed response that expands past the
     * limit is refused while it is still arriving rather than after it has
     * been materialised in full.
     *
     * @throws RemoteMediaFetchException
     */
    private function readBody(ResponseInterface $response, string $url): string
    {
        $bytes = '';

        foreach ($this->http->stream([$response]) as $chunk) {
            $bytes .= $chunk->getContent();

            if (strlen($bytes) > $this->maxBytes) {
                // The rest of the body is of no use to us, so the
                // transfer is aborted rather than left to run.
                $response->cancel();

                throw new RemoteMediaFetchException(
                    url: $url,
                    httpStatus: 413,
                    message: sprintf(
                        'Remote media body exceeds max_promote_bytes (> %d); refusing to keep %s',
                        $this->maxBytes,
                        $url,
                    ),
                );
            }
        }

        return $bytes;
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
            $url = $this->screen($url);
            [$response] = $this->send('HEAD', $url, []);
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
