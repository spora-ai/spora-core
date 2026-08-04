<?php

declare(strict_types=1);

namespace Spora\Core;

/**
 * Resolves the public base URL the operator configured for this Spora instance.
 *
 * Resolution order — first wins:
 *
 *   1. `SPORA_APP_URL` env var
 *   2. The web server's `HTTP_HOST` (request-supplied)
 *   3. The web server's `SERVER_NAME` (Apache `ServerName` directive, set at
 *      server bootstrap — trusted because the operator controls it)
 *   4. `http://localhost` (CLI / worker / console / tests)
 *
 * Does NOT read `X-Forwarded-*` — those headers are spoofable and there is
 * no trusted-proxy allowlist at the application layer. Operators behind a
 * proxy that rewrites `Host` MUST set `SPORA_APP_URL` in `.env`.
 */
final class RequestOrigin
{
    private const LOCALHOST_ORIGIN = 'http://localhost';

    /**
     * Resolve the public base URL.
     */
    public static function detect(): string
    {
        $configured = self::env('SPORA_APP_URL');
        if ($configured !== '') {
            $base = rtrim($configured, '/');
        } elseif (\PHP_SAPI === 'cli') {
            $base = self::LOCALHOST_ORIGIN;
        } else {
            $base = self::detectFrom($_SERVER);
        }
        return $base;
    }

    /**
     * Resolve the public base URL and the path prefix under which Spora is
     * mounted (e.g. `/spora` when running behind a reverse-proxy that
     * mounts the app under a sub-path).
     *
     * @return array{0: string, 1: string} [baseUrl, pathPrefix]
     *   `pathPrefix` is `/foo`-normalized; empty string when no prefix is set.
     */
    public static function detectWithPrefix(): array
    {
        $prefix = self::env('SPORA_APP_PREFIX');
        $prefix = '/' . trim($prefix, '/');
        if ($prefix === '/') {
            $prefix = '';
        }

        return [self::detect(), $prefix];
    }

    /**
     * Build the public origin from a server-variable array. Public for
     * testing — production code calls {@see detect()}.
     *
     * Resolution order: `HTTP_HOST` → `SERVER_NAME` → `http://localhost`.
     *
     * @param array<string, mixed> $server
     */
    public static function detectFrom(array $server): string
    {
        $host = trim((string) ($server['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string) ($server['SERVER_NAME'] ?? ''));
        }
        $url = '';
        if ($host !== '') {
            $scheme = self::resolveScheme($server);
            $defaultPort = $scheme === 'https' ? 443 : 80;
            $port = (int) ($server['SERVER_PORT'] ?? $defaultPort);
            $base = "{$scheme}://" . self::stripPort($host);
            $url = $port !== $defaultPort ? "{$base}:{$port}" : $base;
        }
        return $url !== '' ? $url : self::LOCALHOST_ORIGIN;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function resolveScheme(array $server): string
    {
        $scheme = (string) ($server['REQUEST_SCHEME'] ?? '');
        if ($scheme === '') {
            $https = $server['HTTPS'] ?? null;
            $scheme = ($https !== null && strtolower((string) $https) !== 'off') ? 'https' : 'http';
        }
        return $scheme === 'https' ? 'https' : 'http';
    }

    private static function stripPort(string $host): string
    {
        if ($host === '') {
            return $host;
        }
        if ($host[0] === '[') {
            $close = strpos($host, ']');
            return $close !== false ? substr($host, 0, $close + 1) : $host;
        }
        $colon = strrpos($host, ':');
        return $colon !== false ? substr($host, 0, $colon) : $host;
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return is_string($value) ? trim($value) : '';
    }
}
