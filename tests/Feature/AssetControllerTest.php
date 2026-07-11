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
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end: build a fresh LocalAssetStore in a tmp dir, register the
 * asset route on a router, dispatch requests, and assert on the response.
 *
 * After the opaque-URL refactor, the chat bubble holds `/api/v1/assets/<uuid>`,
 * the controller resolves by UUID via MediaArchiveService::find(), and the
 * on-disk file is found via the row's `asset_token`. Tests below ingest
 * real bytes through the service (which writes the row + file), then GET
 * the opaque URL the chat would actually emit.
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

    $paths   = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $sniffer = new MimeSniffer();
    $logger  = new \Psr\Log\NullLogger();
    $archive = new MediaArchiveService(
        $assetStore,
        new MediaArchiveUrlResolver(
            new RemoteMediaFetcher(HttpClient::create(), $logger, 30, 100 * 1024 * 1024),
            $sniffer,
            $logger,
            true,
            100 * 1024 * 1024,
        ),
        $sniffer,
        new MetadataExtractor($logger, false),
    );

    $controller = new AssetController($archive, $database, $local);

    $container = (new ContainerBuilder())->build();
    $container->set(LocalAssetStore::class, $local);
    $container->set(DatabaseAssetStore::class, $database);
    $container->set(MediaArchiveService::class, $archive);
    $container->set(AssetController::class, $controller);

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

    return [$router, $archive, $tmp, $restore];
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

test('GET /api/v1/assets/{uuid} serves an opaque-URL row from the MediaArchive', function (): void {
    [$router, $archive, $tmp, $restore] = assetTestSetup();

    try {
        // Real ingest through the service: bytes → row + on-disk file.
        // Use the PNG magic-byte header so the MimeSniffer returns
        // 'image/png' (not the hinted 'audio/mpeg') and the controller's
        // Content-Type header matches what the test asserts.
        $png = "\x89PNG\r\n\x1a\n" . 'hello-world';
        $asset = $archive->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
            bytes: $png,
            mime: 'image/png',
            filename: 'test.png',
        ));
        expect($asset->asset_url)->toBe('/api/v1/assets/' . $asset->id);

        $path = parse_url($asset->asset_url, PHP_URL_PATH);

        $request  = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->get('Content-Type'))->toBe('image/png');
        // Bytes might be served as BinaryFileResponse (local mode) or
        // StreamedResponse (DB mode, since the payload is < 1MiB and
        // AutoAssetStore routes small payloads to the DB BLOB). Drain
        // the response body either way.
        ob_start();
        $response->sendContent();
        $body = ob_get_clean();
        expect($body)->toBe($png);
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{uuid} returns 404 for an unknown UUID', function (): void {
    [$router, , $tmp, $restore] = assetTestSetup();

    try {
        $request = Request::create('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(404);
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{filename} returns 404 for a forged HMAC token (legacy fallback)', function (): void {
    [$router, , $tmp, $restore] = assetTestSetup();
    $assetsDir = $tmp . '/assets';

    try {
        // Plant a file with a syntactically-valid-but-bogus legacy HMAC
        // token; the controller's fallback path must reject it. The legacy
        // `<hmac-32hex>.<random-16hex>.<ext>` form is no longer minted
        // post-refactor, but pre-refactor rows continue to be served.
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

test('GET /api/v1/assets/{uuid} sends a Cache-Control header', function (): void {
    [$router, $archive, $tmp, $restore] = assetTestSetup();

    try {
        $asset = $archive->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
            bytes: 'x',
            mime: 'audio/mpeg',
            filename: 'speech.mp3',
        ));

        $path = parse_url($asset->asset_url, PHP_URL_PATH);

        $request  = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->headers->get('Cache-Control'))->toContain('max-age');
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});