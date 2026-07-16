<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaArchiveController;
use Spora\Services\AutoAssetStore;
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
 * Plan §12 B2b — PATCH /api/v1/media/{id} coverage.
 */
test('PATCH filename is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['filename' => 'new.txt']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['filename'])->toBe('new.txt');
});

test('PATCH tags is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['tags' => ['a', 'b']]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['tags'])->toBe(['a', 'b']);
});

test('PATCH metadata is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['metadata' => ['author' => 'me']]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['metadata'])->toBe(['author' => 'me']);
});

test('PATCH prompt is persisted', function (): void {
    [, $service, $controller] = buildUpdateController();
    $asset = ingestSample($service, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['prompt' => 'updated']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['prompt'])->toBe('updated');
});

test('PATCH returns 403 when the asset is owned by a different non-admin user', function (): void {
    [, $service] = buildUpdateController();
    $asset = ingestSample($service, 99);
    [, , $controller] = buildUpdateController(false, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['filename' => 'no.txt']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(403);
});

test('PATCH returns 200 for an admin even when owned by another user', function (): void {
    [, $service] = buildUpdateController(false, 99);
    $asset = ingestSample($service, 99);
    [, , $controller] = buildUpdateController(true, 1);
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['filename' => 'admin.txt']));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
});

/**
 * @return array{0: MediaArchiveService, 1: MediaArchiveService, 2: MediaArchiveController}
 */
function buildUpdateController(bool $isAdmin = true, int $userId = 1): array
{
    $tmp = sys_get_temp_dir() . '/spora-update-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);
    $auth = new class($userId, $isAdmin) extends \Spora\Auth\AuthService {
        public function __construct(private readonly int $uid, private readonly bool $admin) {}
        public function currentUserId(): int
        {
            return $this->uid;
        }
        public function isAdmin(): bool
        {
            return $this->admin;
        }
    };
    $controller = new MediaArchiveController($service, $auth);
    return [$service, $service, $controller];
}

function ingestSample(MediaArchiveService $service, int $userId): \Spora\Models\MediaAsset
{
    return $service->ingest(new MediaIngestRequest(
        bytes: 'hello',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: $userId,
        uploadSource: 'upload',
    ));
}