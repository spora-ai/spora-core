<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery;
use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaEmbedController;
use Spora\Services\AssetStreamer;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaActiveTypesPolicy;
use Spora\Services\MediaArchive\MediaAssetAccess;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * The embed route — the one place script-capable bytes may run their
 * scripts.
 *
 * The assertions are about what the route *refuses* as much as what it
 * serves. A route that serves live HTML is only safe because of the
 * boundaries around it: authenticated only, gated on the operator's
 * toggle, and sandboxed without `allow-same-origin`.
 */
test('the route 404s for a script-capable asset while active types are off', function (): void {
    [$service, $controller] = buildEmbedController(activeTypes: false);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        mime: 'image/svg+xml',
        filename: 'vector.svg',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(404);
});

test('the route serves an inert embed when only active types are on', function (): void {
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: false);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        mime: 'image/svg+xml',
        filename: 'vector.svg',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toContain('image/svg+xml');
    $policy = (string) $resp->headers->get('Content-Security-Policy');
    expect($policy)->toContain('sandbox');
    expect($policy)->not->toContain('allow-scripts');
    expect($policy)->not->toContain('allow-same-origin');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('the route runs scripts when both toggles are on', function (): void {
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: true);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        mime: 'image/svg+xml',
        filename: 'vector.svg',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(200);
    $policy = (string) $resp->headers->get('Content-Security-Policy');
    expect($policy)->toContain('sandbox allow-scripts');
    // `allow-same-origin` would let a same-origin framed document remove
    // its own sandbox attribute, which would make it no more contained
    // than an unsandboxed one.
    expect($policy)->not->toContain('allow-same-origin');
    expect($policy)->toContain("frame-ancestors 'self'");
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('the route does not run scripts when the embed-script toggle is off', function (): void {
    // Both toggles on would be the permissive case; here only the first is
    // on, and the second must not be inferred.
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: false);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<script>alert(1)</script>',
        mime: 'text/html',
        filename: 'page.html',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(200);
    expect((string) $resp->headers->get('Content-Security-Policy'))->not->toContain('allow-scripts');
});

test('the route 404s for a caller who cannot read the asset', function (): void {
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: true, asAdmin: false, currentUserId: 999);

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<script>alert(1)</script>',
        mime: 'text/html',
        filename: 'page.html',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(404);
});

test('the route does not honour a public share token', function (): void {
    // A script-enabled page reachable by anyone with a link would make
    // the operator's domain a phishing host — the operator's users would
    // see the trusted domain in the URL bar.
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: true, asAdmin: false, currentUserId: 999);

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<script>alert(1)</script>',
        mime: 'text/html',
        filename: 'page.html',
        userId: 1,
        publicAccessToken: 'tok-shared',
        uploadSource: 'upload',
    ));

    // The share token is in the query string; the route must not honour it.
    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(404);
});

test('the route 404s for a non-script-capable asset', function (): void {
    // An ordinary asset has nothing to embed, and serving it here would
    // give a second path to the bytes.
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: true);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'plain text',
        mime: 'text/plain',
        filename: 'notes.txt',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(404);
});

test('the route 404s when the storage layer has no payload for the asset', function (): void {
    // A row whose bytes are gone — an external-mode asset, or a local row
    // whose file was swept. The route must not serve a body it does not
    // have, and must not 500.
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: true);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: '<script>alert(1)</script>',
        mime: 'text/html',
        filename: 'page.html',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    // Strip the payload so the store refuses the read.
    Capsule::table('media_assets')->where('id', $asset->id)->update([
        'storage_mode' => 'external',
    ]);

    $resp = $controller->show($asset->id);

    expect($resp->getStatusCode())->toBe(404);
});

test('the route 404s for a missing asset', function (): void {
    [$service, $controller] = buildEmbedController(activeTypes: true, embedScripts: true);

    $resp = $controller->show('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

    expect($resp->getStatusCode())->toBe(404);
});

/**
 * @return array{0: \Spora\Services\MediaArchive\MediaArchiveService, 1: MediaEmbedController}
 */
function buildEmbedController(
    bool $activeTypes,
    bool $embedScripts = false,
    bool $asAdmin = true,
    ?int $currentUserId = 1,
): array {
    $tmp = sys_get_temp_dir() . '/spora-embed-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/assets', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $service  = MediaArchiveTestSupport::buildService(new AutoAssetStore($database, $local, 1_048_576));

    $auth = Mockery::mock(AuthService::class);
    $auth->allows('isAdmin')->andReturn($asAdmin);
    $auth->allows('currentUserId')->andReturn($currentUserId);

    $policy = new MediaActiveTypesPolicy(new Config(
        [
            'SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES'   => $activeTypes,
            'SPORA_MEDIA_ARCHIVE_ALLOW_EMBED_SCRIPTS' => $embedScripts,
        ],
        [
            'SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES'   => Config::SOURCE_ENV,
            'SPORA_MEDIA_ARCHIVE_ALLOW_EMBED_SCRIPTS' => Config::SOURCE_ENV,
        ],
    ));

    $controller = new MediaEmbedController(
        new AssetStreamer($database, $local),
        new MediaAssetAccess($auth),
        $policy,
    );

    return [$service, $controller];
}
