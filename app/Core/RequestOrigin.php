<?php

declare(strict_types=1);

namespace Spora\Core;

final class RequestOrigin
{
    /**
     * Detect the current request origin from $_SERVER globals.
     * Falls back to http://localhost when running in a CLI context (e.g. worker, console).
     */
    public static function detect(): string
    {
        if (\PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
            return 'http://localhost';
        }

        return self::detectFrom($_SERVER);
    }

    /**
     * Detect the public origin **and** the request's path prefix (if any).
     * Returns $baseUrl without trailing slash and $pathPrefix with leading
     * and trailing slashes ("" if no prefix).
     *
     * @return array{0: string, 1: string}
     */
    public static function detectWithPrefix(): array
    {
        if (\PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
            return ['http://localhost', ''];
        }

        $base   = rtrim(self::detectFrom($_SERVER), '/');
        $prefix = isset($_SERVER['HTTP_X_FORWARDED_PREFIX'])
            ? (string) $_SERVER['HTTP_X_FORWARDED_PREFIX']
            : '';
        $prefix = '/' . trim($prefix, '/');
        if ($prefix === '/') {
            $prefix = '';
        }

        return [$base, $prefix];
    }

    /**
     * Build the public origin from a server-variable array. Public for
     * testing — production code calls {@see detect()}.
     *
     * @param array<string, mixed> $server
     */
    public static function detectFrom(array $server): string
    {
        // Trust X-Forwarded-* when behind a reverse proxy (FrankenPHP, nginx,
        // Cloudflare). The operator's `TrustProxies`/`ForwardedHeaders` knob
        // is the only thing that makes this safe — Symfony's Request does
        // the same.
        $scheme = $server['HTTP_X_FORWARDED_PROTO']
            ?? $server['REQUEST_SCHEME']
            ?? 'http';
        $scheme = explode(',', (string) $scheme, 2)[0];
        $scheme = trim($scheme) !== '' ? trim($scheme) : 'http';

        $host = $server['HTTP_X_FORWARDED_HOST']
            ?? $server['HTTP_HOST']
            ?? null;
        if ($host === null) {
            return 'http://localhost';
        }
        $host = explode(',', (string) $host, 2)[0];
        $host = trim($host);
        if ($host === '') {
            return 'http://localhost';
        }

        // Strip port from the host if it is already present; it is only
        // re-appended below when the *server* port (or X-Forwarded-Port)
        // differs from the scheme default. Prevents the
        // http://localhost:8080:8080 regression.
        $hostWithoutPort = $host;
        if (str_starts_with($hostWithoutPort, '[')) {
            $close = strpos($hostWithoutPort, ']');
            if ($close !== false) {
                $hostWithoutPort = substr($hostWithoutPort, 0, $close + 1);
            }
        } elseif (($colon = strrpos($hostWithoutPort, ':')) !== false) {
            $hostWithoutPort = substr($hostWithoutPort, 0, $colon);
        }

        $port = isset($server['HTTP_X_FORWARDED_PORT'])
            ? (int) explode(',', (string) $server['HTTP_X_FORWARDED_PORT'], 2)[0]
            : (isset($server['HTTP_X_FORWARDED_HOST']) || isset($server['HTTP_X_FORWARDED_PROTO'])
                ? ($scheme === 'https' ? 443 : 80)
                : (int) ($server['SERVER_PORT'] ?? 80));

        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port !== $defaultPort) {
            return "{$scheme}://{$hostWithoutPort}:{$port}";
        }

        return "{$scheme}://{$hostWithoutPort}";
    }
}
