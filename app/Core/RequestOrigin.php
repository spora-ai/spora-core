<?php

declare(strict_types=1);

namespace Spora\Core;

/**
 * Resolves the public base URL and path prefix from the container `config`
 * array. Resolution order — first wins:
 *
 *   1. `config['app_url']` (from `config.php`, `SPORA_APP_URL`, or the
 *      default seeder in {@see ContainerDefinitions})
 *   2. Web-server `HTTP_HOST` (request-supplied)
 *   3. Web-server `SERVER_NAME` (Apache `ServerName` — trusted; operator-controlled)
 *   4. `http://localhost` (CLI / worker / console / tests)
 *
 * For the path prefix: `config['app_prefix']` defaults to `/spora` (the
 * packaged admin UI lives at `public/spora/`; plugins at
 * `public/plugins/<name>/`). Operators hosting their own frontend at the
 * host root opt out by setting `app_prefix = ''` in `config.php` or
 * `SPORA_APP_PREFIX=""`.
 *
 * Does NOT read `X-Forwarded-*` — those headers are spoofable and there is
 * no trusted-proxy allowlist. Operators behind a proxy that rewrites `Host`
 * MUST set `app_url` explicitly.
 */
final class RequestOrigin
{
    private const LOCALHOST_ORIGIN = 'http://localhost';

    /**
     * Default path prefix when `config['app_prefix']` is not set.
     * Matches the packaged admin UI (`public/spora/`) and plugin URLs
     * (`public/plugins/<name>/`).
     */
    private const DEFAULT_APP_PREFIX = '/spora';

    /**
     * Resolve the public base URL.
     *
     * @param array<string, mixed> $config the container's merged config array
     */
    public static function detect(array $config = []): string
    {
        $configured = (string) ($config['app_url'] ?? '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        if (\PHP_SAPI === 'cli') {
            return self::LOCALHOST_ORIGIN;
        }

        return self::buildFromServer($_SERVER);
    }

    /**
     * Resolve the public base URL and the path prefix under which Spora is
     * mounted.
     *
     * @param array<string, mixed> $config the container's merged config array
     * @return array{0: string, 1: string} [baseUrl, pathPrefix]
     *   `pathPrefix` is `/foo`-normalized; empty string when no prefix is set.
     */
    public static function detectWithPrefix(array $config = []): array
    {
        $prefix = (string) ($config['app_prefix'] ?? self::DEFAULT_APP_PREFIX);
        $prefix = self::normalizePrefix($prefix);

        return [self::detect($config), $prefix];
    }

    /**
     * Build the public origin from a server-variable array. Public for
     * testing — production code calls {@see detect()} with the container
     * config.
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

    public static function normalizePrefix(string $prefix): string
    {
        $normalized = '/' . trim($prefix, '/');
        return $normalized === '/' ? '' : $normalized;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function buildFromServer(array $server): string
    {
        $host = self::stripPort((string) $server['HTTP_HOST']);
        $scheme = self::resolveScheme($server);
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($server['SERVER_PORT'] ?? $defaultPort);

        $url = "{$scheme}://{$host}";
        return $port !== $defaultPort ? "{$url}:{$port}" : $url;
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
}
