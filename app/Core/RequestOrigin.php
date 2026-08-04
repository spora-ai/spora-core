<?php

declare(strict_types=1);

namespace Spora\Core;

/**
 * Resolves the public base URL and path prefix from the container `config`
 * array. The framework's config system (config.php + SPOra_* env vars via
 * {@see ContainerDefinitions::configDefinition}) is the single
 * source of truth — this class does NOT read `$_ENV` or `getenv()` directly.
 *
 * Resolution order for {@see detect()} — first wins:
 *
 *   1. `config['app_url']` (set by `config.php`, `SPORA_APP_URL`, or the
 *      default seeder in {@see ContainerDefinitions})
 *   2. The web server's `HTTP_HOST` (request-supplied)
 *   3. The web server's `SERVER_NAME` (Apache `ServerName`, set at server
 *      bootstrap — trusted because the operator controls it)
 *   4. `http://localhost` (CLI / worker / console / tests)
 *
 * For {@see detectWithPrefix()}, the same fallback applies for the host,
 * and `config['app_prefix']` (default `/spora`) supplies the path prefix
 * because the Spora admin UI ships under `public/spora/` and plugins ship
 * under `public/plugins/<name>/`. Operators hosting their own frontend at
 * the host root opt out with `app_prefix = ''` in `config.php` or by
 * exporting `SPORA_APP_PREFIX=""`.
 *
 * Does NOT read `X-Forwarded-*` — those headers are spoofable and there is
 * no trusted-proxy allowlist at the application layer. Operators behind a
 * proxy that rewrites `Host` MUST set `app_url` (via `config.php` or
 * `SPORA_APP_URL`).
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

    /**
     * Normalize a path prefix value: leading/trailing slashes stripped,
     * a bare `/` (or empty) collapses to `''`, anything else is wrapped as
     * `/foo`.
     */
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
        $host = (string) $server['HTTP_HOST'];
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
