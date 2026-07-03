<?php

declare(strict_types=1);

namespace Tests\Feature;

use DI\ContainerBuilder;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Paths;
use Spora\Core\Router;
use Spora\Core\SecurityManager;
use Spora\Http\AssetController;
use Spora\Services\LocalAssetStore;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end: build a fresh LocalAssetStore in a tmp dir, register the
 * asset route on a router, dispatch requests, and assert on the response.
 */
function assetTestSetup(): array
{
    $tmp = sys_get_temp_dir() . '/spora-asset-ctrl-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);

    // Paths::storage() respects SPORA_STORAGE_DIR ahead of the base path,
    // so we route the asset directory to a tmp dir without touching the
    // readonly $basePath property.
    $previous = getenv('SPORA_STORAGE_DIR');
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $store    = new LocalAssetStore($paths, $security);

    $container = (new ContainerBuilder())->build();
    $container->set(LocalAssetStore::class, $store);
    $container->set(AssetController::class, new AssetController($store));

    $router = new Router($container, static function (MiddlewareRouteCollector $r): void {
        $r->addRoute('GET', '/api/v1/assets/{filename}', [AssetController::class, 'show'], []);
    });

    $restore = static function () use ($previous): void {
        if ($previous === false) {
            putenv('SPORA_STORAGE_DIR');
            unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        } else {
            putenv("SPORA_STORAGE_DIR={$previous}");
            $_ENV['SPORA_STORAGE_DIR']    = $previous;
            $_SERVER['SPORA_STORAGE_DIR'] = $previous;
        }
    };

    return [$router, $store, $tmp, $restore];
}

function assetTestTeardown(string $tmp): void
{
    if (! is_dir($tmp)) {
        return;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
}

test('GET /api/v1/assets/{filename} serves a file minted by LocalAssetStore', function (): void {
    [$router, $store, $tmp, $restore] = assetTestSetup();

    try {
        $ref = $store->store('hello-world', mime: 'audio/mpeg', filename: 'speech.mp3');
        $url = $ref->url;
        $path = parse_url($url, PHP_URL_PATH);
        expect($path)->toStartWith('/api/v1/assets/');

        $request = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->get('Content-Type'))->toBe('audio/mpeg');
        // BinaryFileResponse::getContent() returns false unless sendContent()
        // has been called; read the underlying file directly instead.
        expect(file_get_contents($response->getFile()->getPathname()))->toBe('hello-world');
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{filename} returns 404 for an unknown filename', function (): void {
    [$router, , $tmp, $restore] = assetTestSetup();

    try {
        $request = Request::create('/api/v1/assets/does-not-exist.mp3', 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(404);
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{filename} returns 404 for a forged token', function (): void {
    [$router, , $tmp, $restore] = assetTestSetup();
    $assetsDir = $tmp . '/assets';

    try {
        // Plant a file with a syntactically-valid-but-bogus token; the HMAC
        // check must reject it.
        @mkdir($assetsDir, 0755, recursive: true);
        $forgedToken = str_repeat('a', 32) . '.' . str_repeat('b', 16);
        file_put_contents($assetsDir . "/{$forgedToken}.mp3", 'forged');

        $request = Request::create("/api/v1/assets/{$forgedToken}.mp3", 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(404);
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{filename} sends a Cache-Control header', function (): void {
    [$router, $store, $tmp, $restore] = assetTestSetup();

    try {
        $ref = $store->store('x', mime: 'audio/mpeg', filename: 'speech.mp3');
        $path = parse_url($ref->url, PHP_URL_PATH);

        $request = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->headers->get('Cache-Control'))->toContain('max-age');
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});
