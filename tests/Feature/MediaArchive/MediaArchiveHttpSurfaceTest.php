<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaAllowedTypesController;
use Spora\Http\MediaArchiveController;
use Spora\Http\MediaUploadController;
use Spora\Http\PublicMediaController;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * HTTP-surface tests for the new media-upload, allowed-types, public
 * sharing, and refresh-token endpoints.
 */
function buildUploadHttpFixtures(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-http-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $service   = MediaArchiveTestSupport::buildService($assetStore);
    $auth      = MediaArchiveTestSupport::buildAuth();
    $registry  = MediaArchiveTestSupport::buildConverterRegistry();

    $allowed = new MediaAllowedTypesService($registry, new \Spora\Drivers\DriverFactory(
        new \Psr\Log\NullLogger(),
        new \Spora\Services\LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
        300,
    ));
    return [
        $assetStore,
        $service,
        $auth,
        $registry,
        $allowed,
        new MediaUploadController($service, $allowed, $auth, new \Spora\Services\MediaArchive\MimeSniffer()),
        new MediaAllowedTypesController($allowed),
        new MediaArchiveController($service, $auth),
        new PublicMediaController($database, $local),
        $paths,
    ];
}

test('MediaAllowedTypesController returns the allowlist JSON', function (): void {
    [, , , , $allowed, , $allowedCtrl] = buildUploadHttpFixtures();
    $resp = $allowedCtrl->index(Request::create('/api/v1/media/allowed-types', 'GET'));
    expect($resp->getStatusCode())->toBe(200);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['mime_types'])->toContain('text/plain');
    expect($payload['data']['mime_types'])->toContain('application/pdf');
    expect($payload['data']['extensions'])->toContain('pdf');
});

test('MediaUploadController returns 415 on a disallowed MIME', function (): void {
    [, , , , , $uploadCtrl] = buildUploadHttpFixtures();
    $tmpFile = tempnam(sys_get_temp_dir(), 'spora-test');
    file_put_contents($tmpFile, "MZ" . str_repeat("\0", 100));
    $request = Request::create('/api/v1/media', 'POST', files: [
        'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $tmpFile,
            'whatever.exe',
            'application/x-msdownload',
            null,
            true,
        ),
    ]);
    $resp = $uploadCtrl->store($request);
    expect($resp->getStatusCode())->toBe(415);
    unlink($tmpFile);
});

test('PublicMediaController returns 404 on a token mismatch', function (): void {
    [, , , , , , , , $publicCtrl] = buildUploadHttpFixtures();
    $req = Request::create('/api/v1/public/media/00000000-0000-0000-0000-000000000000?token=bad', 'GET');
    $resp = $publicCtrl->show('00000000-0000-0000-0000-000000000000', $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('PublicMediaController returns 404 on bad UUID', function (): void {
    [, , , , , , , , $publicCtrl] = buildUploadHttpFixtures();
    $req = Request::create('/api/v1/public/media/not-a-uuid?token=anything', 'GET');
    $resp = $publicCtrl->show('not-a-uuid', $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('MediaArchiveController update returns 404 for unknown id', function (): void {
    [, , , , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $req = Request::create('/api/v1/media/00000000-0000-0000-0000-000000000000', 'PATCH', content: '{}');
    $req->headers->set('Content-Type', 'application/json');
    $resp = $archiveCtrl->update('00000000-0000-0000-0000-000000000000', $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('MediaArchiveController refreshPublicToken returns 404 for unknown id', function (): void {
    [, , , , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $req = Request::create('/api/v1/media/00000000-0000-0000-0000-000000000000/public-token/refresh', 'POST');
    $resp = $archiveCtrl->refreshPublicToken('00000000-0000-0000-0000-000000000000', $req);
    expect($resp->getStatusCode())->toBe(404);
});

test('MediaArchiveController update rejects non-string filename with 400', function (): void {
    [, $service, , , , , , $archiveCtrl] = buildUploadHttpFixtures();
    // Create a media row first
    $asset = $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['filename' => 12345]),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $archiveCtrl->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(400);
});

test('MediaArchiveController update rejects non-array tags with 400', function (): void {
    [$assetStore, $service, $auth, , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $asset = $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['tags' => 'not-an-array']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $archiveCtrl->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(400);
});

test('MediaArchiveController update rejects non-bool public_access_enabled with 400', function (): void {
    [$assetStore, $service, $auth, , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $asset = $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['public_access_enabled' => 'yes']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $archiveCtrl->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(400);
});

test('MediaArchiveController update persists tags and prompt', function (): void {
    [$assetStore, $service, $auth, , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $asset = $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['tags' => ['draft', 'redacted'], 'prompt' => 'updated prompt']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $archiveCtrl->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['tags'])->toBe(['draft', 'redacted']);
    expect($payload['data']['prompt'])->toBe('updated prompt');
});

test('MediaArchiveController update with public_access_enabled mints a token', function (): void {
    [$assetStore, $service, $auth, , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $asset = $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['public_access_enabled' => true]),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $archiveCtrl->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $payload = json_decode($resp->getContent(), true);
    expect((string) $payload['data']['public_access_token'])->not->toBe('');
});

test('MediaArchiveController update with public_access_enabled false clears the token', function (): void {
    [$assetStore, $service, $auth, , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $asset = $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['public_access_enabled' => true]),
    );
    $req->headers->set('Content-Type', 'application/json');
    $archiveCtrl->update($asset->id, $req);
    $req2 = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['public_access_enabled' => false]),
    );
    $req2->headers->set('Content-Type', 'application/json');
    $resp = $archiveCtrl->update($asset->id, $req2);
    expect($resp->getStatusCode())->toBe(200);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['public_access_token'])->toBeNull();
});

test('MediaArchiveController refreshPublicToken mints a fresh token', function (): void {
    [$assetStore, $service, $auth, , , , , $archiveCtrl] = buildUploadHttpFixtures();
    $asset = $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    // Enable sharing first
    $req = Request::create(
        "/api/v1/media/{$asset->id}",
        'PATCH',
        content: json_encode(['public_access_enabled' => true]),
    );
    $req->headers->set('Content-Type', 'application/json');
    $archiveCtrl->update($asset->id, $req);
    $first = \Spora\Models\MediaAsset::query()->find($asset->id);
    $firstToken = $first->public_access_token;

    // Refresh
    $req2 = Request::create("/api/v1/media/{$asset->id}/public-token/refresh", 'POST');
    $resp = $archiveCtrl->refreshPublicToken($asset->id, $req2);
    expect($resp->getStatusCode())->toBe(200);
    $second = \Spora\Models\MediaAsset::query()->find($asset->id);
    expect($second->public_access_token)->not->toBe($firstToken);
});
