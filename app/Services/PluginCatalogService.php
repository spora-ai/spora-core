<?php

declare(strict_types=1);

namespace Spora\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Core\Paths;
use Spora\Services\Exceptions\CatalogUnavailableException;
use Spora\Services\Exceptions\MalformedCatalogException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Discovers Spora plugins on Packagist.
 *
 * Calls Packagist's public search endpoint
 * (https://packagist.org/search.json?q={query}&type=spora-plugin) and filters
 * results to packages with type === 'spora-plugin'. Results are cached on
 * disk at `<storage>/.spora_plugin_catalog.json`, keyed by `hash('sha256', $query)`,
 * with a TTL (default 1 hour).
 *
 * On HTTP 429 or transport errors: serves the stale cache if one exists;
 * otherwise throws CatalogUnavailableException (mapped to HTTP 503).
 *
 * On malformed JSON: throws MalformedCatalogException (mapped to HTTP 502).
 */
final class PluginCatalogService
{
    public const DEFAULT_TTL_SECONDS = 3600;
    public const CACHE_FILENAME = '.spora_plugin_catalog.json';
    public const CACHE_VERSION = 2;

    /**
     * Composer packages with type === 'spora-plugin' are listed in the
     * Browse tab. The `type` filter is also passed to Packagist as a
     * query hint, but we re-filter on the response body to defend
     * against keyword pollution.
     */
    public const SPORA_PLUGIN_TYPE = 'spora-plugin';

    private const CACHE_ENTRY_TTL = 'ttl';
    private const CACHE_ENTRY_PACKAGES = 'packages';

    private readonly ClockInterface $clock;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Paths $paths,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new Clock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Search Packagist for Spora plugins matching `$query` (empty string = list all).
     *
     * @return list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>
     */
    public function search(string $query): array
    {
        $cached = $this->readCache($query);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $packages = $this->fetchFromPackagist($query);
            $this->writeCache($query, $packages);
            return $packages;
        } catch (CatalogUnavailableException $e) {
            $stale = $this->readStaleCache($query);
            if ($stale !== null) {
                $this->logger->warning('PluginCatalogService: serving stale cache after upstream error', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                return $stale;
            }
            throw $e;
        }
    }

    public function clearCache(): void
    {
        $path = $this->cachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array{cached_at: int, ttl_seconds: int, source: 'cache'|'network'}
     */
    public function getCacheInfo(): array
    {
        $path = $this->cachePath();
        $mtime = is_file($path) ? filemtime($path) : false;

        return [
            'cached_at'   => $mtime === false ? 0 : (int) $mtime,
            'ttl_seconds' => $this->ttlSeconds,
            'source'      => $mtime === false ? 'network' : 'cache',
        ];
    }

    /**
     * @return list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>
     */
    private function fetchFromPackagist(string $query): array
    {
        $url = 'https://packagist.org/search.json?' . http_build_query([
            'q'    => $query,
            'type' => self::SPORA_PLUGIN_TYPE,
        ], encoding_type: PHP_QUERY_RFC3986);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new CatalogUnavailableException(
                'Packagist transport error: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if ($status === 429) {
            throw new CatalogUnavailableException('Packagist rate limit hit (HTTP 429).');
        }

        if ($status < 200 || $status >= 300) {
            throw new CatalogUnavailableException(
                sprintf('Packagist returned HTTP %d.', $status),
            );
        }

        $body = $response->getContent(false);

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new MalformedCatalogException(
                'Packagist returned malformed JSON: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new MalformedCatalogException('Packagist response was not a JSON object.');
        }

        $results = $decoded['results'] ?? null;
        if (!is_array($results)) {
            throw new MalformedCatalogException('Packagist response missing the "results" array.');
        }

        $packages = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['type'] ?? null) !== self::SPORA_PLUGIN_TYPE) {
                continue;
            }
            $packages[] = $this->mapPackage($row);
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}
     */
    private function mapPackage(array $row): array
    {
        $name = (string) ($row['name'] ?? '');
        $description = (string) ($row['description'] ?? '');
        $version = isset($row['version']) && is_string($row['version']) && $row['version'] !== ''
            ? $row['version']
            : null;
        $downloads = isset($row['downloads']) ? (int) $row['downloads'] : 0;
        $favers = isset($row['favers']) ? (int) $row['favers'] : 0;
        $repository = isset($row['repository']) && is_string($row['repository']) && $row['repository'] !== ''
            ? $row['repository']
            : null;
        $homepage = isset($row['homepage']) && is_string($row['homepage']) && $row['homepage'] !== ''
            ? $row['homepage']
            : null;

        return [
            'name'        => $name,
            'description' => $description,
            'version'     => $version,
            'downloads'   => $downloads,
            'favers'      => $favers,
            'repository'  => $repository,
            'homepage'    => $homepage,
        ];
    }

    /**
     * @param list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}> $packages
     */
    private function writeCache(string $query, array $packages): void
    {
        $now = $this->now();
        $cache = $this->readCacheFile();
        $cache['version'] = self::CACHE_VERSION;
        /** @var array<string, array{ttl: int, packages: list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>}> $entries */
        $entries = is_array($cache['entries'] ?? null) ? $cache['entries'] : [];
        $entries[hash('sha256', $query)] = [
            self::CACHE_ENTRY_TTL      => $now,
            self::CACHE_ENTRY_PACKAGES => $packages,
        ];
        $cache['entries'] = $entries;
        $this->writeCacheFile($cache);
    }

    /**
     * @return list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>|null
     */
    private function readCache(string $query): ?array
    {
        $entry = $this->readCacheEntry($query);
        if ($entry === null) {
            return null;
        }
        $age = $this->now() - (int) $entry[self::CACHE_ENTRY_TTL];
        if ($age >= $this->ttlSeconds) {
            return null;
        }
        /** @var list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}> $packages */
        $packages = $entry[self::CACHE_ENTRY_PACKAGES];
        return $packages;
    }

    /**
     * @return list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>|null
     */
    private function readStaleCache(string $query): ?array
    {
        $entry = $this->readCacheEntry($query);
        if ($entry === null) {
            return null;
        }
        /** @var list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}> $packages */
        $packages = $entry[self::CACHE_ENTRY_PACKAGES];
        return $packages;
    }

    /**
     * @return array{ttl: int, packages: list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>}|null
     */
    private function readCacheEntry(string $query): ?array
    {
        $cache = $this->readCacheFile();
        /** @var mixed $versionRaw */
        $versionRaw = $cache['version'] ?? null;
        if ((int) $versionRaw !== self::CACHE_VERSION) {
            return null;
        }
        /** @var mixed $entries */
        $entries = $cache['entries'] ?? null;
        if (!is_array($entries)) {
            return null;
        }
        $key = hash('sha256', $query);
        if (!isset($entries[$key]) || !is_array($entries[$key])) {
            return null;
        }
        /** @var array{ttl: int, packages: list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>} $entry */
        $entry = $entries[$key];
        return $entry;
    }

    /**
     * @return array{version?: mixed, entries?: mixed}
     */
    private function readCacheFile(): array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array{version?: mixed, entries?: mixed} $decoded */
        return $decoded;
    }

    /**
     * @param array{version?: mixed, entries?: mixed} $cache
     */
    private function writeCacheFile(array $cache): void
    {
        $path = $this->cachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $json = json_encode($cache, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write plugin catalog cache: {$path}");
        }
        @rename($tmp, $path);
    }

    private function cachePath(): string
    {
        return $this->paths->storage(self::CACHE_FILENAME);
    }

    private function now(): int
    {
        return (int) $this->clock->now()->getTimestamp();
    }
}
