<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\PublicMediaController;
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

/**
 * @return array{0: MediaArchiveService, 1: PublicMediaController}
 */
function buildPublicController(): array
{
    $tmp = sys_get_temp_dir() . '/spora-public-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $service = MediaArchiveTestSupport::buildService(new \Spora\Services\AutoAssetStore($database, $local, 1_048_576));
    return [$service, new PublicMediaController($database, $local)];
}