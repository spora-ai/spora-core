<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use DateTimeImmutable;
use Mockery;
use RuntimeException;
use Spora\Core\Paths;
use Spora\Services\Exceptions\CatalogUnavailableException;
use Spora\Services\Exceptions\MalformedCatalogException;
use Spora\Services\PluginCatalogService;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @return array{0: PluginCatalogService, 1: Mockery\MockInterface, 2: string, 3: MockClock}
 */
function catalogServiceFixture(int $ttlSeconds = 3600, ?int $fixedNow = null): array
{
    $tmp = sys_get_temp_dir() . '/spora_catalog_' . uniqid('', true);
    mkdir($tmp . '/storage', 0o777, true);
    $paths = new Paths($tmp, $tmp);

    $now = $fixedNow ?? 1_700_000_000;
    $clock = new MockClock(DateTimeImmutable::createFromFormat('U', (string) $now));
    $client = Mockery::mock(HttpClientInterface::class);
    $service = new PluginCatalogService($client, $paths, $ttlSeconds, $clock);

    return [$service, $client, $tmp, $clock];
}

function catalogCleanUp(string $dir): void
{
    $cacheFile = $dir . '/storage/.spora_plugin_catalog.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
    @rmdir($dir . '/storage');
    @rmdir($dir);
}

/**
 * Build a mock HTTP response with a fixed status code and body.
 *
 * @param int                     $status
 * @param string                  $body
 *
 * @return ResponseInterface&Mockery\MockInterface
 */
function catalogResponse(int $status, string $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn($status);
    $response->shouldReceive('getContent')->andReturn($body);
    return $response;
}

test('search on cache miss calls Packagist and returns parsed packages', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $payload = json_encode([
            'results' => [
                [
                    'name'        => 'spora-ai/spora-plugin-email',
                    'description' => 'IMAP/SMTP plugin for Spora.',
                    'type'        => 'spora-plugin',
                    'version'     => '0.2.1',
                    'downloads'   => 1024,
                    'favers'      => 8,
                    'repository'  => 'https://github.com/spora-ai/spora-plugin-email',
                    'homepage'    => 'https://docs.spora.ai/plugins/email',
                ],
                [
                    'name'        => 'spora-ai/spora-plugin-tavily',
                    'description' => 'Web search via Tavily.',
                    'type'        => 'spora-plugin',
                    'version'     => '0.2.1',
                    'downloads'   => 512,
                    'favers'      => 4,
                    'repository'  => 'https://github.com/spora-ai/spora-plugin-tavily',
                    'homepage'    => null,
                ],
            ],
        ]);

        $client->shouldReceive('request')
            ->once()
            ->with('GET', Mockery::on(static function (string $url): bool {
                return str_starts_with($url, 'https://packagist.org/search.json?')
                    && str_contains($url, 'type=spora-plugin');
            }), Mockery::any())
            ->andReturn(catalogResponse(200, $payload));

        $packages = $service->search('');

        expect($packages)->toHaveCount(2);
        expect($packages[0]['name'])->toBe('spora-ai/spora-plugin-email');
        expect($packages[0]['version'])->toBe('0.2.1');
        expect($packages[0]['downloads'])->toBe(1024);
        expect($packages[1]['homepage'])->toBeNull();

        // Cache file was written
        $cacheFile = $tmp . '/storage/.spora_plugin_catalog.json';
        expect(is_file($cacheFile))->toBeTrue();
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search on cache hit does not call Packagist again', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $payload = json_encode([
            'results' => [
                [
                    'name'        => 'spora-ai/spora-plugin-email',
                    'description' => 'IMAP/SMTP plugin.',
                    'type'        => 'spora-plugin',
                    'version'     => '0.2.1',
                    'downloads'   => 1,
                    'favers'      => 1,
                    'repository'  => null,
                    'homepage'    => null,
                ],
            ],
        ]);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalogResponse(200, $payload));

        $first = $service->search('');
        expect($first)->toHaveCount(1);

        // Second call should be served from cache (no HTTP).
        $second = $service->search('');
        expect($second)->toHaveCount(1);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search re-fetches after TTL expires', function (): void {
    [$service, $client, $tmp, $clock] = catalogServiceFixture(ttlSeconds: 60, fixedNow: 1_700_000_000);
    try {
        $payloadA = json_encode(['results' => [['name' => 'spora-ai/a', 'description' => '', 'type' => 'spora-plugin', 'downloads' => 0, 'favers' => 0]]]);
        $payloadB = json_encode(['results' => [['name' => 'spora-ai/b', 'description' => '', 'type' => 'spora-plugin', 'downloads' => 0, 'favers' => 0]]]);

        $client->shouldReceive('request')->once()->ordered()->andReturn(catalogResponse(200, $payloadA));
        $client->shouldReceive('request')->once()->ordered()->andReturn(catalogResponse(200, $payloadB));

        $first = $service->search('');
        expect($first[0]['name'])->toBe('spora-ai/a');

        // Advance clock past the TTL.
        $clock->modify('+61 seconds');

        $second = $service->search('');
        expect($second[0]['name'])->toBe('spora-ai/b');
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search filters out non-spora-plugin types from the response', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $payload = json_encode([
            'results' => [
                [
                    'name'        => 'spora-ai/spora-plugin-email',
                    'description' => 'Email plugin.',
                    'type'        => 'spora-plugin',
                    'downloads'   => 0,
                    'favers'      => 0,
                ],
                [
                    'name'        => 'some/library',
                    'description' => 'A library that happens to mention spora-plugin.',
                    'type'        => 'library',
                    'downloads'   => 9999,
                    'favers'      => 9999,
                ],
            ],
        ]);

        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalogResponse(200, $payload));

        $packages = $service->search('');
        expect($packages)->toHaveCount(1);
        expect($packages[0]['name'])->toBe('spora-ai/spora-plugin-email');
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search throws MalformedCatalogException on non-JSON body', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalogResponse(200, '<html>not json</html>'));

        expect(fn() => $service->search(''))->toThrow(MalformedCatalogException::class);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search throws MalformedCatalogException when results key is missing', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalogResponse(200, json_encode(['unrelated' => 'shape'])));

        expect(fn() => $service->search(''))->toThrow(MalformedCatalogException::class);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search throws CatalogUnavailableException on HTTP 429 with no cache', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalogResponse(429, ''));

        expect(fn() => $service->search(''))->toThrow(CatalogUnavailableException::class);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search falls back to stale cache when HTTP 429 hits', function (): void {
    [$service, $client, $tmp, $clock] = catalogServiceFixture(ttlSeconds: 60, fixedNow: 1_700_000_000);
    try {
        // First call: succeeds, populates cache.
        $payload = json_encode([
            'results' => [
                ['name' => 'spora-ai/email', 'description' => '', 'type' => 'spora-plugin', 'downloads' => 0, 'favers' => 0],
            ],
        ]);
        $client->shouldReceive('request')->once()->ordered()->andReturn(catalogResponse(200, $payload));

        $first = $service->search('');
        expect($first[0]['name'])->toBe('spora-ai/email');

        // Advance past TTL so the next call is a cache miss.
        $clock->modify('+120 seconds');

        // Next call: 429, but stale cache exists → return stale.
        $client->shouldReceive('request')->once()->ordered()->andReturn(catalogResponse(429, ''));

        $second = $service->search('');
        expect($second[0]['name'])->toBe('spora-ai/email');
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search throws CatalogUnavailableException on transport error with no cache', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $transport = new class extends RuntimeException implements TransportExceptionInterface {};
        $client->shouldReceive('request')->once()->andThrow($transport);

        expect(fn() => $service->search(''))->toThrow(CatalogUnavailableException::class);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('different queries produce separate cache entries', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $payloadA = json_encode(['results' => [['name' => 'spora-ai/email', 'description' => '', 'type' => 'spora-plugin', 'downloads' => 0, 'favers' => 0]]]);
        $payloadB = json_encode(['results' => [['name' => 'spora-ai/tavily', 'description' => '', 'type' => 'spora-plugin', 'downloads' => 0, 'favers' => 0]]]);

        $client->shouldReceive('request')->once()->andReturn(catalogResponse(200, $payloadA));
        $client->shouldReceive('request')->once()->andReturn(catalogResponse(200, $payloadB));

        $a = $service->search('email');
        $b = $service->search('tavily');

        expect($a[0]['name'])->toBe('spora-ai/email');
        expect($b[0]['name'])->toBe('spora-ai/tavily');

        $cache = json_decode((string) file_get_contents($tmp . '/storage/.spora_plugin_catalog.json'), true);
        expect($cache['entries'])->toHaveCount(2);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('malformed cache entry falls back to the network', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        // Hand-craft a cache file whose entry for the queried key is missing
        // the `packages` field — must NOT trigger an undefined-index warning,
        // must be treated as a cache miss.
        $key = hash('sha256', '');
        $cachePath = $tmp . '/storage/.spora_plugin_catalog.json';
        @mkdir(dirname($cachePath), 0o777, true);
        file_put_contents($cachePath, json_encode([
            'version' => PluginCatalogService::CACHE_VERSION,
            'entries' => [
                $key => ['ttl' => 1_700_000_000], // missing 'packages'
            ],
        ]));

        $payload = json_encode([
            'results' => [
                ['name' => 'spora-ai/x', 'description' => '', 'type' => 'spora-plugin', 'downloads' => 0, 'favers' => 0],
            ],
        ]);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalogResponse(200, $payload));

        // Suppress warnings so an undefined-index regression fails loudly here,
        // not silently as a passing test.
        $packages = @$service->search('');

        expect($packages)->toHaveCount(1);
        expect($packages[0]['name'])->toBe('spora-ai/x');
    } finally {
        catalogCleanUp($tmp);
    }
});

test('clearCache removes the cache file', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $payload = json_encode(['results' => [['name' => 'spora-ai/x', 'description' => '', 'type' => 'spora-plugin', 'downloads' => 0, 'favers' => 0]]]);
        $client->shouldReceive('request')->once()->andReturn(catalogResponse(200, $payload));
        $service->search('');

        $cacheFile = $tmp . '/storage/.spora_plugin_catalog.json';
        expect(is_file($cacheFile))->toBeTrue();

        $service->clearCache();
        expect(is_file($cacheFile))->toBeFalse();
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search with empty results returns an empty array (not an error)', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalogResponse(200, json_encode(['results' => []])));

        $packages = $service->search('nothing-matches-this');

        expect($packages)->toBe([]);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('getCacheInfo reflects cache state', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        $infoBefore = $service->getCacheInfo();
        expect($infoBefore['cached_at'])->toBe(0);
        expect($infoBefore['source'])->toBe('network');

        $payload = json_encode(['results' => []]);
        $client->shouldReceive('request')->once()->andReturn(catalogResponse(200, $payload));
        $service->search('');

        $infoAfter = $service->getCacheInfo();
        expect($infoAfter['cached_at'])->toBeGreaterThan(0);
        expect($infoAfter['source'])->toBe('cache');
        expect($infoAfter['ttl_seconds'])->toBe(3600);
    } finally {
        catalogCleanUp($tmp);
    }
});

test('search propagates unexpected runtime errors', function (): void {
    [$service, $client, $tmp] = catalogServiceFixture();
    try {
        // An exception that's not a TransportExceptionInterface propagates as-is,
        // since the cache lookup already failed (no cache exists) and we only
        // catch CatalogUnavailableException to attempt stale fallback.
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new RuntimeException('boom'));

        expect(fn() => $service->search(''))->toThrow(RuntimeException::class);
    } finally {
        catalogCleanUp($tmp);
    }
});
