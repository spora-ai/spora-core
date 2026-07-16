<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\PublicMediaController;
use Spora\Models\MediaAsset;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * Plan §12 B2b — PublicMediaController coverage.
 */
test('returns 200 with the bytes for the correct token', function (): void {
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'public content',
        mime: 'text/plain',
        filename: 'public.txt',
        userId: 1,
        publicAccessToken: 'tok-12345',
        uploadSource: 'upload',
    ));
    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-12345", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toContain('text/plain');
    expect($resp->headers->get('Cache-Control'))->toContain('private');
    // StreamedResponse body is captured into the sendContent() stream;
    // invoke it to verify the bytes match.
    ob_start();
    $resp->sendContent();
    $body = ob_get_clean();
    expect($body)->toContain('public content');
});

test('returns 404 for the wrong token', function (): void {
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'secret',
        mime: 'text/plain',
        filename: 'secret.txt',
        userId: 1,
        publicAccessToken: 'tok-12345',
        uploadSource: 'upload',
    ));
    $req = Request::create("/api/v1/public/media/{$asset->id}?token=wrong", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('returns 404 for a missing token', function (): void {
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'secret',
        mime: 'text/plain',
        filename: 'secret.txt',
        userId: 1,
        publicAccessToken: 'tok-12345',
        uploadSource: 'upload',
    ));
    $req = Request::create("/api/v1/public/media/{$asset->id}", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('response carries Referrer-Policy: no-referrer', function (): void {
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'public content',
        mime: 'text/plain',
        filename: 'public.txt',
        userId: 1,
        publicAccessToken: 'tok-12345',
        uploadSource: 'upload',
    ));
    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-12345", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

test('returns 200 with streamed bytes from local storage', function (): void {
    // Build with a tight DB ceiling (1 KiB) so even a 2 KiB payload
    // routes through LocalAssetStore and exercises the path branch.
    $tmp = sys_get_temp_dir() . '/spora-public-local-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    // Threshold of 1024 → anything over 1 KiB routes to local storage.
    $service = MediaArchiveTestSupport::buildService(new \Spora\Services\AutoAssetStore($database, $local, 1024));
    $controller = new PublicMediaController($database, $local);

    $bytes = str_repeat('L', 2048);
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: $bytes,
        mime: 'text/plain',
        filename: 'local.txt',
        userId: 1,
        publicAccessToken: 'tok-local',
        uploadSource: 'upload',
    ));
    expect($asset->storage_mode)->toBe('local');

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-local", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toContain('text/plain');
    expect($resp->headers->get('Referrer-Policy'))->toBe('no-referrer');
    expect($resp->headers->get('Cache-Control'))->toContain('private');

    ob_start();
    $resp->sendContent();
    $body = ob_get_clean();
    expect(strlen($body))->toBe(strlen($bytes));
    expect($body)->toContain('LLLL'); // first few bytes match
});

test('returns 404 when the storage layer throws AssetStorageException', function (): void {
    // Force the local store's readFromAsset to throw by nulling the
    // asset_token — the controller must translate that to 404, not 500.
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'x',
        mime: 'text/plain',
        filename: 'tiny.txt',
        userId: 1,
        publicAccessToken: 'tok-x',
        uploadSource: 'upload',
    ));
    // Switch the asset's storage_mode so the controller tries the
    // local-store read path, then strip asset_token so it throws.
    MediaAsset::query()->where('id', $asset->id)->update([
        'storage_mode' => 'local',
        'asset_token'  => null,
    ]);

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-x", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('returns 404 when an unknown storage mode is encountered', function (): void {
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'x',
        mime: 'text/plain',
        filename: 'mystery.bin',
        userId: 1,
        publicAccessToken: 'tok-mystery',
        uploadSource: 'upload',
    ));
    MediaAsset::query()->where('id', $asset->id)->update([
        'storage_mode' => 'unsupported-mode',
    ]);

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-mystery", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('falls back to application/octet-stream when mime_type is empty', function (): void {
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'binary blob',
        mime: '', // empty mime — controller must default it
        filename: 'blob.bin',
        userId: 1,
        publicAccessToken: 'tok-bin',
        uploadSource: 'upload',
    ));
    // Force mime_type to empty string in the DB so the controller's
    // fallback path is exercised. `''` passes through `??` (unlike
    // null) so the asset store returns `''` and line 82 fires.
    MediaAsset::query()->where('id', $asset->id)->update(['mime_type' => '']);

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-bin", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toContain('application/octet-stream');
});

test('returns 404 for a non-UUID id', function (): void {
    [$service, $controller] = buildPublicController();
    $req = Request::create('/api/v1/public/media/not-a-uuid?token=anything', 'GET');
    $resp = $controller->show('not-a-uuid', $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('returns 404 when the asset has no public_access_token', function (): void {
    [$service, $controller] = buildPublicController();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'private',
        mime: 'text/plain',
        filename: 'private.txt',
        userId: 1,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));

    $req = Request::create("/api/v1/public/media/{$asset->id}?token=any", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('returns 200 when the asset_token points at a real file inside storage', function (): void {
    // Exercise the BinaryFileResponse branch (line 108 onward) by
    // making readFromAsset() return a real file inside the storage root.
    $tmp = sys_get_temp_dir() . '/spora-public-bfr-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/assets', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);

    // Hand-write a file inside storage and a row pointing at it.
    file_put_contents($tmp . '/assets/abc123.txt', 'inside-storage-bytes');
    $service = MediaArchiveTestSupport::buildService(new \Spora\Services\AutoAssetStore($database, $local, 1024));
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'irrelevant — we override storage_mode below',
        mime: 'text/plain',
        filename: 'bfr.txt',
        userId: 1,
        publicAccessToken: 'tok-bfr',
        uploadSource: 'upload',
    ));
    MediaAsset::query()->where('id', $asset->id)->update([
        'storage_mode' => 'local',
        'asset_token'  => 'abc123',
    ]);

    $controller = new PublicMediaController($database, $local);
    $req = Request::create("/api/v1/public/media/{$asset->id}?token=tok-bfr", 'GET');
    $resp = $controller->show($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Referrer-Policy'))->toBe('no-referrer');
    expect($resp->headers->get('Cache-Control'))->toContain('private');
});

/**
 * @return array{0: MediaArchiveService, 1: PublicMediaController}
 */
function buildPublicController(): array
{
    [$service, $controller] = buildPublicControllerWithPaths();
    return [$service, $controller];
}

/**
 * @return array{0: MediaArchiveService, 1: PublicMediaController, 2: Paths}
 */
function buildPublicControllerWithPaths(): array
{
    $tmp = sys_get_temp_dir() . '/spora-public-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    // Default DatabaseAssetStore ceiling is 64 KiB; bump it so all
    // tests in this file go through the data_url branch by default
    // unless the test forces local storage via payload size.
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $service = MediaArchiveTestSupport::buildService(new \Spora\Services\AutoAssetStore($database, $local, 1_048_576));
    return [$service, new PublicMediaController($database, $local), $paths];
}
