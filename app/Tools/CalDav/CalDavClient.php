<?php

declare(strict_types=1);

namespace Spora\Tools\CalDav;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * HTTP transport layer for CalDAV: timeout resolution, URI normalization,
 * ETag normalization, request dispatch, and HTTP error logging. Exposes a
 * single `request()` method so the tool class can focus on CalDAV semantics
 * (REPORT/PUT/GET/DELETE) rather than transport details.
 */
final class CalDavClient
{
    private const DEFAULT_HTTP_TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $options Symfony HttpClient options (headers, body, auth_basic, timeout, …)
     */
    public function request(string $method, string $url, array $options): ResponseInterface
    {
        $this->logHttpRequest($method, $url, $options);
        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', [
                'method' => $method,
                'url' => $url,
                'exception' => $e,
            ]);
            throw new CalDavException("CalDAV {$method} {$url} failed: {$e->getMessage()}", 0, $e);
        }
        $this->logHttpResponse($method, $url, $response->getStatusCode(), $response->getHeaders(false));
        return $response;
    }

    /**
     * @param array<string, mixed> $settings Tool settings
     */
    public function effectiveTimeout(array $settings): int
    {
        $setting = (int) ($settings['core.caldav.http_timeout'] ?? 0);
        if ($setting > 0) {
            return $setting;
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : self::DEFAULT_HTTP_TIMEOUT;
    }

    /**
     * Resolve a CalDAV event URI returned by the server against the configured base URL.
     */
    public function resolveEventUri(string $eventUri, string $baseUrl): string
    {
        if ($this->isAbsoluteHttpUrl($eventUri) || $baseUrl === '') {
            return $eventUri;
        }

        $origin = $this->extractOrigin($baseUrl);
        if ($origin === null) {
            return $eventUri;
        }

        return $origin . '/' . ltrim($eventUri, '/');
    }

    private function isAbsoluteHttpUrl(string $uri): bool
    {
        return str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://');
    }

    private function extractOrigin(string $baseUrl): ?string
    {
        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin;
    }

    /**
     * Normalize an ETag value to the RFC 7232 quoted form.
     */
    public function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);
        if ($etag === '') {
            return '';
        }
        if (str_starts_with($etag, 'W/')) {
            $inner = substr($etag, 2);
            if (!str_starts_with($inner, '"')) {
                $inner = '"' . $inner;
            }
            if (!str_ends_with($inner, '"')) {
                $inner = $inner . '"';
            }
            return 'W/' . $inner;
        }
        if (!str_starts_with($etag, '"')) {
            $etag = '"' . $etag;
        }
        if (!str_ends_with($etag, '"')) {
            $etag = $etag . '"';
        }
        return $etag;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    public function logHttpError(string $method, string $url, int $statusCode, string $responseBody, array $headers): void
    {
        // Per docs/08_logging.md: CalDAV response bodies may carry event content
        // (PII like summaries, descriptions, attendees). Log only a short, ASCII
        // preview at ERROR; keep the full body confined to DEBUG.
        $preview = mb_substr($responseBody, 0, 200);
        if (mb_strlen($responseBody) > 200) {
            $preview .= '…';
        }

        $this->logger?->error('CalDAV HTTP Error', [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'response_preview' => $preview,
            'www_authenticate' => $headers['www-authenticate'][0] ?? null,
        ]);

        $this->logger?->debug('CalDavCalendarTool: full HTTP error body', [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'response' => $responseBody,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function logHttpRequest(string $method, string $url, array $options): void
    {
        $this->logger?->debug('CalDavCalendarTool: HTTP request', [
            'method' => $method,
            'url' => $url,
            'headers' => $options['headers'] ?? [],
            'auth_basic' => isset($options['auth_basic']) ? [$options['auth_basic'][0], '***'] : null,
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function logHttpResponse(string $method, string $url, int $statusCode, array $headers): void
    {
        $this->logger?->debug('CalDavCalendarTool: HTTP response', [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'www_authenticate' => $headers['www-authenticate'][0] ?? null,
        ]);
    }
}
