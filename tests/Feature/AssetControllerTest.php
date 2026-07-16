<?php

declare(strict_types=1);

namespace Tests\Feature;

use DI\ContainerBuilder;
use FilesystemIterator;
use Mockery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spora\Auth\AuthService;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Paths;
use Spora\Core\Router;
use Spora\Core\SecurityManager;
use Spora\Http\AssetController;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaIngestDecoder;
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
function assetTestSetup(bool $asAdmin = true): array
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
        \Tests\Support\MediaArchiveTestSupport::buildConverterRegistry(),
        new MediaIngestDecoder(),
    );

    // Auth mock — by default, the test requester is treated as an admin
    // so the existing tests (which don't set up an owning user) still
    // reach the streaming path. Tests that want a non-admin scenario
    // can construct their own mock and pass it via this helper's return.
    $auth = Mockery::mock(AuthService::class);
    $auth->allows('isAdmin')->andReturn($asAdmin);
    $auth->allows('currentUserId')->andReturn($asAdmin ? 1 : null);

    $controller = new AssetController($archive, $database, $local, $auth);

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
        expect($asset->asset_url)->toBe('/api/v1/assets/' . $asset->id . '.png');

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

test('GET /api/v1/assets/{uuid}.{ext} resolves to the same row as {uuid}', function (): void {
    // Browsers fetch the asset URL with an `.ext` suffix so the saved
    // filename has the right extension. The controller strips the suffix
    // before the UUID lookup, so both forms resolve identically.
    [$router, $archive, $tmp, $restore] = assetTestSetup();

    try {
        $png = "\x89PNG\r\n\x1a\n" . 'with-extension';
        $asset = $archive->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
            bytes: $png,
            mime: 'image/png',
            filename: 'test.png',
        ));
        expect($asset->asset_url)->toEndWith('.png');

        $pathWithExt = parse_url($asset->asset_url, PHP_URL_PATH);
        expect($pathWithExt)->toMatch('/\.png$/');

        $request  = Request::create($pathWithExt, 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->get('Content-Type'))->toBe('image/png');
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

test('GET /api/v1/assets/{uuid} returns 404 when the requester is not the owner and not an admin', function (): void {
    // assetTestSetup() with $asAdmin=false: the mock returns isAdmin=false
    // and currentUserId=null, so canAccessAsset denies the request even
    // though the row exists. The 404 is the standard envelope — never a
    // 403, to avoid leaking the UUID's existence.
    [$router, $archive, $tmp, $restore] = assetTestSetup(asAdmin: false);

    try {
        $asset = $archive->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
            bytes: 'secret-image-bytes',
            mime: 'image/png',
            filename: 'test.png',
        ));

        $path = parse_url($asset->asset_url, PHP_URL_PATH);

        $request  = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(404);
        expect($response->headers->get('Content-Type'))->toContain('application/json');
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{uuid} lets the owning user through', function (): void {
    [$router, $archive, $tmp, $restore] = assetTestSetup();

    try {
        // The admin mock returns currentUserId=1 — create that user, an
        // agent, and a task, then ingest an asset tied to them. The
        // controller's ownership check finds asset.task.user_id == 1
        // and lets the request through.
        $userId = \bootAuthLayer()->register('asset-owner@example.com', 'Password1!', 'Owner');

        $agent = \Spora\Models\Agent::create([
            'user_id'   => $userId,
            'name'      => 'asset-owner-test',
            'max_steps' => 5,
            'is_active' => true,
        ]);
        $task = \Spora\Models\Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'RUNNING',
            'user_prompt' => 'test',
        ]);

        $asset = $archive->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
            bytes: "\x89PNG\r\n\x1a\n" . 'owned-bytes',
            mime: 'image/png',
            filename: 'owned.png',
            agentId: $agent->id,
            taskId: $task->id,
        ));

        $path = parse_url($asset->asset_url, PHP_URL_PATH);

        $request  = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(200);
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{uuid} returns 404 for external-mode rows (no Spora-side bytes)', function (): void {
    [$router, $archive, $tmp, $restore] = assetTestSetup();

    try {
        // Ingest a URL that the resolver can't fetch → external mode,
        // no payload bytes. The controller's streamAsset() falls into
        // the 'external' branch which throws AssetStorageException,
        // caught and returned as 404.
        $asset = $archive->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
            url: 'https://unreachable.invalid/missing.png',
        ));
        expect($asset->storage_mode)->toBe('external');

        $path = parse_url($asset->asset_url, PHP_URL_PATH);

        $request  = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(404);
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});

test('GET /api/v1/assets/{uuid} returns 404 when storage_mode is unsupported', function (): void {
    [$router, $archive, $tmp, $restore] = assetTestSetup();

    try {
        // Manually craft a row with an unsupported storage_mode to hit
        // the `default` arm of the match in streamAsset(). This branch
        // exists as a defense-in-depth guard — any new storage_mode
        // value lands here until AssetController is updated.
        $asset = $archive->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
            bytes: 'whatever',
            mime: 'text/plain',
        ));
        \Spora\Models\MediaAsset::query()
            ->where('id', $asset->id)
            ->update(['storage_mode' => 'mystery_mode']);

        $path = '/api/v1/assets/' . $asset->id;
        $request  = Request::create($path, 'GET');
        $response = $router->dispatch($request);

        expect($response->getStatusCode())->toBe(404);
    } finally {
        assetTestTeardown($tmp);
        $restore();
    }
});
