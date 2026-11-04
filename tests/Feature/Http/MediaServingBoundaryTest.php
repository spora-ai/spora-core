<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Mockery;
use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\AssetController;
use Spora\Http\PublicMediaController;
use Spora\Models\MediaAsset;
use Spora\Services\AssetStreamer;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * The serving boundary.
 *
 * A script-capable asset must leave the server inert: it renders, it does
 * not execute. `nosniff` stops the browser re-interpreting the declared
 * type, and the `sandbox` policy puts a script-capable document in an
 * opaque origin so it cannot reach the operator's origin.
 *
 * The per-type split is the point. A test that only asserted the presence
 * of `sandbox` would pass if it were applied to every response, which would
 * say nothing about the rule it claims to pin.
 */
test('the public route sandboxes a script-capable asset', function (): void {
    [$service, $controller] = buildBoundaryPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        mime: 'image/svg+xml',
        filename: 'vector.svg',
        userId: 1,
        publicAccessToken: 'tok-svg',
        uploadSource: 'upload',
    ));

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-svg", 'GET');
    $resp = $controller->show($asset->id, $req);

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toContain('image/svg+xml');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($resp->headers->get('Content-Security-Policy'))->toBe('sandbox');
});

test('the public route sandboxes an HTML asset', function (): void {
    [$service, $controller] = buildBoundaryPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<script>alert(1)</script>',
        mime: 'text/html',
        filename: 'page.html',
        userId: 1,
        publicAccessToken: 'tok-html',
        uploadSource: 'upload',
    ));

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-html", 'GET');
    $resp = $controller->show($asset->id, $req);

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Security-Policy'))->toBe('sandbox');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('the public route does not sandbox a non-script-capable asset', function (): void {
    [$service, $controller] = buildBoundaryPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'not markup',
        mime: 'text/plain',
        filename: 'notes.txt',
        userId: 1,
        publicAccessToken: 'tok-txt',
        uploadSource: 'upload',
    ));

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-txt", 'GET');
    $resp = $controller->show($asset->id, $req);

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($resp->headers->get('Content-Security-Policy'))->toBeNull();
});

test('the public route sandboxes a script-capable asset read from local storage', function (): void {
    // The BinaryFileResponse branch — a different code path from the
    // streamed-bytes branch, and a rule that held on only one of them
    // would be no rule at all.
    [$service, $controller, $tmp] = buildBoundaryPublicControllerWithPaths();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        mime: 'image/svg+xml',
        filename: 'vector.svg',
        userId: 1,
        publicAccessToken: 'tok-svg',
        uploadSource: 'upload',
    ));

    file_put_contents($tmp . '/assets/local-vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    MediaAsset::query()->where('id', $asset->id)->update([
        'storage_mode' => 'local',
        'asset_token'  => 'local-vector',
        'mime_type'    => 'image/svg+xml',
    ]);

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-svg", 'GET');
    $resp = $controller->show($asset->id, $req);

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Security-Policy'))->toBe('sandbox');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('the asset route sandboxes a script-capable asset', function (): void {
    [$service, $streamer, $auth] = buildBoundaryAssetController();

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        mime: 'image/svg+xml',
        filename: 'vector.svg',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $streamer->stream($asset, 'inline');

    expect($resp)->not->toBeNull();
    expect($resp->headers->get('Content-Security-Policy'))->toBe('sandbox');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('the asset route does not sandbox a non-script-capable asset', function (): void {
    [$service, $streamer] = buildBoundaryAssetController();

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'not markup',
        mime: 'text/plain',
        filename: 'notes.txt',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $streamer->stream($asset, 'inline');

    expect($resp)->not->toBeNull();
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($resp->headers->get('Content-Security-Policy'))->toBeNull();
});

test('the agent-api inline route sandboxes a script-capable asset', function (): void {
    [$service, $streamer] = buildBoundaryAssetController();

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<script>alert(1)</script>',
        mime: 'text/html',
        filename: 'page.html',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    // The agent-API route streams with `inline` — the disposition that
    // renders the bytes in the browser rather than downloading them.
    $resp = $streamer->stream($asset, 'inline');

    expect($resp)->not->toBeNull();
    expect($resp->headers->get('Content-Security-Policy'))->toBe('sandbox');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

/**
 * @return array{0: MediaArchiveService, 1: PublicMediaController}
 */
function buildBoundaryPublicController(): array
{
    [$service, $controller] = buildBoundaryPublicControllerWithPaths();
    return [$service, $controller];
}

/**
 * @return array{0: MediaArchiveService, 1: PublicMediaController, 2: string}
 */
function buildBoundaryPublicControllerWithPaths(): array
{
    $tmp = sys_get_temp_dir() . '/spora-public-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/assets', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $service  = MediaArchiveTestSupport::buildService(new \Spora\Services\AutoAssetStore($database, $local, 1_048_576));

    return [$service, new PublicMediaController($database, $local), $tmp];
}

/**
 * @return array{0: MediaArchiveService, 1: AssetStreamer, 2: AssetController}
 */
function buildBoundaryAssetController(): array
{
    $tmp = sys_get_temp_dir() . '/spora-asset-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/assets', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $service  = MediaArchiveTestSupport::buildService(new \Spora\Services\AutoAssetStore($database, $local, 1_048_576));
    $streamer = new AssetStreamer($database, $local);

    $auth = Mockery::mock(AuthService::class);
    $auth->allows('isAdmin')->andReturn(true);
    $auth->allows('currentUserId')->andReturn(1);

    return [$service, $streamer, new AssetController($service, $local, $streamer, $auth)];
}
