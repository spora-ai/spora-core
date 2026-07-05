<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\PluginsController;
use Spora\Plugins\PluginLoader;
use Spora\Services\Exceptions\CatalogUnavailableException;
use Spora\Services\Exceptions\MalformedCatalogException;
use Spora\Services\PluginCatalogService;
use Spora\Services\PluginMetadataExtractor;
use Spora\Services\PluginsService;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

const PLUGINS_CATALOG_FIXTURE = BASE_PATH . '/tests/Fixtures/plugins_inventory';

/**
 * Build a PluginsController with the catalog enabled and a real
 * PluginCatalogService that talks to a Mockery HTTP client.
 *
 * @return array{0: PluginsController, 1: AuthMiddleware, 2: string}
 */
function catalog_makeController(int $ttlSeconds = 3600): array
{
    $tmp = sys_get_temp_dir() . '/spora_catalog_ctrl_' . uniqid('', true);
    mkdir($tmp . '/storage', 0o777, true);
    $paths = new Paths($tmp, $tmp);

    $client = Mockery::mock(HttpClientInterface::class);
    $clock = new MockClock(DateTimeImmutable::createFromFormat('U', '1700000000'));
    $catalog = new PluginCatalogService($client, $paths, $ttlSeconds, $clock);

    $loader = new PluginLoader([PLUGINS_CATALOG_FIXTURE], null);
    $loader->boot();
    $service = new PluginsService($loader, new PluginMetadataExtractor());

    $authService = bootAuthLayer();
    $authMw = new AuthMiddleware($authService);

    return [new PluginsController($service, $catalog, true), $authMw, $tmp];
}

/**
 * Same as catalog_makeController but with the catalog feature flag OFF.
 *
 * @return array{0: PluginsController, 1: AuthMiddleware, 2: string}
 */
function catalog_makeControllerDisabled(): array
{
    $tmp = sys_get_temp_dir() . '/spora_catalog_ctrl_' . uniqid('', true);
    mkdir($tmp . '/storage', 0o777, true);

    $loader = new PluginLoader([PLUGINS_CATALOG_FIXTURE], null);
    $loader->boot();
    $service = new PluginsService($loader, new PluginMetadataExtractor());

    $authService = bootAuthLayer();
    $authMw = new AuthMiddleware($authService);

    return [new PluginsController($service, null, false), $authMw, $tmp];
}

function catalog_cleanUp(string $dir): void
{
    $cache = $dir . '/storage/.spora_plugin_catalog.json';
    if (is_file($cache)) {
        @unlink($cache);
    }
    @rmdir($dir . '/storage');
    @rmdir($dir);
}

/**
 * @return ResponseInterface&Mockery\MockInterface
 */
function catalog_mockResponse(int $status, string $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn($status);
    $response->shouldReceive('getContent')->andReturn($body);
    return $response;
}

describe('PluginsController::catalog', function (): void {
    it('returns the catalog for an authenticated non-admin user', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('browse@example.com', 'ValidPass1!', 'Browse');
        simulateLoggedInSession($userId, 'browse@example.com');

        [$controller, $authMw, $tmp] = catalog_makeController();
        try {
            $payload = json_encode([
                'results' => [
                    [
                        'name'        => 'spora-ai/spora-plugin-email',
                        'description' => 'IMAP/SMTP plugin for Spora.',
                        'type'        => 'spora-plugin',
                        'version'     => '0.2.1',
                        'downloads'   => 5,
                        'favers'      => 1,
                        'repository'  => 'https://github.com/spora-ai/spora-plugin-email',
                        'homepage'    => null,
                    ],
                ],
            ]);

            /** @var HttpClientInterface&Mockery\MockInterface $client */
            $client = Mockery::mock(HttpClientInterface::class);
            $client->shouldReceive('request')
                ->once()
                ->with('GET', Mockery::on(static fn(string $url): bool => str_starts_with($url, 'https://packagist.org/search.json?')), Mockery::any())
                ->andReturn(catalog_mockResponse(200, $payload));

            $clock = new MockClock(DateTimeImmutable::createFromFormat('U', '1700000000'));
            $catalog = new PluginCatalogService($client, new Paths($tmp, $tmp), 3600, $clock);

            $controller = new PluginsController(
                new PluginsService((function () {
                    $l = new PluginLoader([PLUGINS_CATALOG_FIXTURE], null);
                    $l->boot();
                    return $l;
                })(), new PluginMetadataExtractor()),
                $catalog,
                true,
            );

            $request = jsonRequest('GET', '/api/v1/plugins/catalog?q=email');
            $response = callController($controller, 'catalog', $request, [$authMw]);

            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);

            expect($body)->toHaveKey('data');
            expect($body['data']['packages'])->toHaveCount(1);
            expect($body['data']['packages'][0]['name'])->toBe('spora-ai/spora-plugin-email');
            expect($body['data']['packages'][0]['version'])->toBe('0.2.1');
            expect($body['data']['packages'][0]['repository'])->toBe('https://github.com/spora-ai/spora-plugin-email');
            expect($body['data']['cached_at'])->toBeGreaterThan(0);
            expect($body['data']['ttl_seconds'])->toBe(3600);
        } finally {
            catalog_cleanUp($tmp);
        }
    });

    it('returns 404 when the catalog feature flag is off', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('browse-off@example.com', 'ValidPass1!', 'BrowseOff');
        simulateLoggedInSession($userId, 'browse-off@example.com');

        [$controller, $authMw, $tmp] = catalog_makeControllerDisabled();
        try {
            $request = jsonRequest('GET', '/api/v1/plugins/catalog');
            $response = callController($controller, 'catalog', $request, [$authMw]);

            expect($response->getStatusCode())->toBe(404);
            $body = json_decode($response->getContent(), true);
            expect($body['error']['code'])->toBe('NOT_FOUND');
        } finally {
            catalog_cleanUp($tmp);
        }
    });

    it('throws (500) when the catalog is enabled but the service is not wired', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('wiring@example.com', 'ValidPass1!', 'Wiring');
        simulateLoggedInSession($userId, 'wiring@example.com');

        $tmp = sys_get_temp_dir() . '/spora_catalog_wiring_' . uniqid('', true);
        mkdir($tmp . '/storage', 0o777, true);

        $loader = new PluginLoader([PLUGINS_CATALOG_FIXTURE], null);
        $loader->boot();
        $service = new PluginsService($loader, new PluginMetadataExtractor());

        // Feature flag ON, but service is null — a wiring bug the operator
        // cannot fix via config. Must surface as 500, not be hidden as 404.
        $controller = new PluginsController($service, null, true);
        $authMw = new AuthMiddleware($authService);

        try {
            $request = jsonRequest('GET', '/api/v1/plugins/catalog');
            expect(fn() => callController($controller, 'catalog', $request, [$authMw]))
                ->toThrow(RuntimeException::class);
        } finally {
            catalog_cleanUp($tmp);
        }
    });

    it('returns 401 for anonymous users', function (): void {
        clearSession();

        [$controller, $authMw, $tmp] = catalog_makeController();
        try {
            $request = jsonRequest('GET', '/api/v1/plugins/catalog');
            expect(fn() => callController($controller, 'catalog', $request, [$authMw]))
                ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
        } finally {
            catalog_cleanUp($tmp);
        }
    });

    it('returns 503 when the upstream catalog is unavailable', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('catalog-503@example.com', 'ValidPass1!', 'Catalog503');
        simulateLoggedInSession($userId, 'catalog-503@example.com');

        $tmp = sys_get_temp_dir() . '/spora_catalog_503_' . uniqid('', true);
        mkdir($tmp . '/storage', 0o777, true);

        $client = Mockery::mock(HttpClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalog_mockResponse(429, ''));

        $clock = new MockClock(DateTimeImmutable::createFromFormat('U', '1700000000'));
        $catalog = new PluginCatalogService($client, new Paths($tmp, $tmp), 3600, $clock);

        $loader = new PluginLoader([PLUGINS_CATALOG_FIXTURE], null);
        $loader->boot();
        $service = new PluginsService($loader, new PluginMetadataExtractor());

        $controller = new PluginsController($service, $catalog, true);
        $authMw = new AuthMiddleware($authService);

        try {
            $request = jsonRequest('GET', '/api/v1/plugins/catalog?q=anything');
            expect(fn() => callController($controller, 'catalog', $request, [$authMw]))
                ->toThrow(CatalogUnavailableException::class);
        } finally {
            catalog_cleanUp($tmp);
        }
    });

    it('returns 502-equivalent (MalformedCatalogException) on bad JSON', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('catalog-502@example.com', 'ValidPass1!', 'Catalog502');
        simulateLoggedInSession($userId, 'catalog-502@example.com');

        $tmp = sys_get_temp_dir() . '/spora_catalog_502_' . uniqid('', true);
        mkdir($tmp . '/storage', 0o777, true);

        $client = Mockery::mock(HttpClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->andReturn(catalog_mockResponse(200, 'not json at all'));

        $clock = new MockClock(DateTimeImmutable::createFromFormat('U', '1700000000'));
        $catalog = new PluginCatalogService($client, new Paths($tmp, $tmp), 3600, $clock);

        $loader = new PluginLoader([PLUGINS_CATALOG_FIXTURE], null);
        $loader->boot();
        $service = new PluginsService($loader, new PluginMetadataExtractor());

        $controller = new PluginsController($service, $catalog, true);
        $authMw = new AuthMiddleware($authService);

        try {
            $request = jsonRequest('GET', '/api/v1/plugins/catalog');
            expect(fn() => callController($controller, 'catalog', $request, [$authMw]))
                ->toThrow(MalformedCatalogException::class);
        } finally {
            catalog_cleanUp($tmp);
        }
    });

    it('passes an empty query through to the service', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('catalog-empty@example.com', 'ValidPass1!', 'CatalogEmpty');
        simulateLoggedInSession($userId, 'catalog-empty@example.com');

        $tmp = sys_get_temp_dir() . '/spora_catalog_empty_' . uniqid('', true);
        mkdir($tmp . '/storage', 0o777, true);

        $client = Mockery::mock(HttpClientInterface::class);
        $client->shouldReceive('request')
            ->once()
            ->with('GET', Mockery::on(static fn(string $url): bool => str_contains($url, 'q=') || str_contains($url, 'q&')), Mockery::any())
            ->andReturn(catalog_mockResponse(200, json_encode(['results' => []])));

        $clock = new MockClock(DateTimeImmutable::createFromFormat('U', '1700000000'));
        $catalog = new PluginCatalogService($client, new Paths($tmp, $tmp), 3600, $clock);

        $loader = new PluginLoader([PLUGINS_CATALOG_FIXTURE], null);
        $loader->boot();
        $service = new PluginsService($loader, new PluginMetadataExtractor());

        $controller = new PluginsController($service, $catalog, true);
        $authMw = new AuthMiddleware($authService);

        try {
            $request = jsonRequest('GET', '/api/v1/plugins/catalog');
            $response = callController($controller, 'catalog', $request, [$authMw]);

            expect($response->getStatusCode())->toBe(200);
            $body = json_decode($response->getContent(), true);
            expect($body['data']['packages'])->toBe([]);
        } finally {
            catalog_cleanUp($tmp);
        }
    });
});
