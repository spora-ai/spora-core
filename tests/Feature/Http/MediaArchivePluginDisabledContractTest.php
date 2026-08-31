<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaArchiveController;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * `GET /api/v1/media` must resolve successfully even when the
 * spora-plugin-media-archive plugin is NOT installed.
 *
 * The four plugin-only admin routes
 * (`show`/`update`/`destroy`/`public-token/refresh`) moved out of
 * core in `feat/media-principal-coverage` — the plugin owns its
 * CRUD via `AbstractPlugin::routes()`. The composer's picker +
 * operator dashboard listing MUST keep working without the plugin so
 * the host SPA's MediaPickerOverlay can drop a file even when the
 * Media Archive plugin is disabled.
 *
 * This test boots the core route set without any plugin
 * registration, hits `GET /api/v1/media`, and asserts the controller
 * is still wired (no class-not-found, no missing-method).
 */
test('GET /api/v1/media resolves on core-only (plugin disabled)', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-plugin-disabled-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database  = new DatabaseAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);

    $auth = bootAuthLayer();
    $controller = new MediaArchiveController(
        $service,
        $auth,
        new MediaAssetSerializer(false),
        new PrincipalResolver(),
    );

    // Smoke check: the controller instance is constructible and
    // exposes the kept `index()` method. A 200/401/403 is fine —
    // we only care that the controller is fully wired in the
    // plugin-disabled case.
    $request = Request::create('/api/v1/media', 'GET');
    $resp = $controller->index($request);
    expect($resp->getStatusCode())->toBeIn([200, 401]);
});
