<?php

declare(strict_types=1);

namespace Spora\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Core\Paths;
use Spora\Services\Exceptions\CatalogUnavailableException;
use Spora\Services\Exceptions\MalformedCatalogException;
use Spora\Services\Exceptions\PluginCatalogCacheWriteException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Discovers Spora plugins on Packagist and serves a cached, filtered view
 * via `GET /api/v1/plugins/catalog`. See `docs/18_plugin_author_guide.md`.
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
        /** @var list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}> */
        return $entry[self::CACHE_ENTRY_PACKAGES];
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
        /** @var list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}> */
        return $entry[self::CACHE_ENTRY_PACKAGES];
    }

    /**
     * @return array{ttl: int, packages: list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>}|null
     */
    private function readCacheEntry(string $query): ?array
    {
        $cache = $this->readCacheFile();
        /** @var mixed $entries */
        $entries = $cache['entries'] ?? null;
        if (!is_array($entries)) {
            return null;
        }
        return $this->validateCacheEntry($entries, hash('sha256', $query));
    }

    /**
     * @param array<string, mixed> $entries
     *
     * @return array{ttl: int, packages: list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>}|null
     */
    private function validateCacheEntry(array $entries, string $key): ?array
    {
        $entry = $entries[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        // Defense against partially-written / hand-edited cache files: only treat
        // well-shaped entries as hits; missing or wrong-typed fields fall through
        // to a network fetch instead of triggering undefined-index warnings.
        if (!isset($entry[self::CACHE_ENTRY_TTL], $entry[self::CACHE_ENTRY_PACKAGES])
            || !is_array($entry[self::CACHE_ENTRY_PACKAGES])
        ) {
            return null;
        }
        /** @var array{ttl: int, packages: list<array{name: string, description: string, version: ?string, downloads: int, favers: int, repository: ?string, homepage: ?string}>} $entry */
        return $entry;
    }

    /**
     * @return array{version?: mixed, entries?: mixed}
     */
    private function readCacheFile(): array
    {
        $decoded = $this->decodeCacheFile();
        if ($decoded === null) {
            return [];
        }

        // Treat version mismatches as no cache: the next writeCache() rebuilds
        // the file under the current schema instead of trusting the stale shape.
        if ((int) ($decoded['version'] ?? null) !== self::CACHE_VERSION) {
            return [];
        }

        /** @var array{version?: mixed, entries?: mixed} $decoded */
        return $decoded;
    }

    /**
     * Read + JSON-decode the on-disk cache file, returning null for any failure
     * (missing, unreadable, malformed, or non-object body). Lets readCacheFile()
     * stay focused on the version check.
     *
     * @return array<string, mixed>|null
     */
    private function decodeCacheFile(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

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
            throw new PluginCatalogCacheWriteException("Unable to write plugin catalog cache: {$path}");
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
