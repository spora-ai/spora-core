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
 * Plan §12 B2b — public-access token lifecycle.
 */
test('PATCH public_access_enabled:true mints a token and returns public_url', function (): void {
    [, , $controller] = buildSharingController();
    $asset = ingestSharedSample();
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => true]));
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['public_access_token'])->not->toBeNull();
    expect($body['data']['public_url'])->toContain('token=' . $body['data']['public_access_token']);
});

test('PATCH public_access_enabled:false clears the token', function (): void {
    [, , $controller] = buildSharingController();
    $asset = ingestSharedSample();
    // First enable
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => true]));
    $req->headers->set('Content-Type', 'application/json');
    $controller->update($asset->id, $req);
    // Then disable
    $req2 = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => false]));
    $req2->headers->set('Content-Type', 'application/json');
    $resp = $controller->update($asset->id, $req2);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['public_access_token'])->toBeNull();
    expect($body['data']['public_url'])->toBeNull();
});

test('POST /api/v1/media/{id}/public-token/refresh rotates the token', function (): void {
    [, , $controller] = buildSharingController();
    $asset = ingestSharedSample();
    // Enable sharing
    $req = Request::create("/api/v1/media/{$asset->id}", 'PATCH', content: json_encode(['public_access_enabled' => true]));
    $req->headers->set('Content-Type', 'application/json');
    $controller->update($asset->id, $req);
    $first = \Spora\Models\MediaAsset::query()->find($asset->id)->public_access_token;
    expect($first)->not->toBeNull();
    // Refresh
    $req2 = Request::create("/api/v1/media/{$asset->id}/public-token/refresh", 'POST');
    $resp = $controller->refreshPublicToken($asset->id, $req2);
    expect($resp->getStatusCode())->toBe(200);
    $second = \Spora\Models\MediaAsset::query()->find($asset->id)->public_access_token;
    expect($second)->not->toBe($first);
});

test('refresh is forbidden for non-owner non-admin', function (): void {
    // Asset is owned by user 99; request comes from user 1 (non-admin).
    [$ingester] = buildSharingController(false, 99);
    $asset = ingestSharedSample(99);
    // Re-fetch the controller with a non-admin caller (user 1).
    [, , $controller] = buildSharingController(false, 1);
    $req = Request::create("/api/v1/media/{$asset->id}/public-token/refresh", 'POST');
    $resp = $controller->refreshPublicToken($asset->id, $req);
    expect($resp->getStatusCode())->toBe(403);
});

/**
 * @return array{0: MediaArchiveService, 1: MediaArchiveService, 2: MediaArchiveController}
 */
function buildSharingController(bool $isAdmin = true, int $userId = 1): array
{
    $tmp = sys_get_temp_dir() . '/spora-sharing-' . bin2hex(random_bytes(4));
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
    return [$service, $service, new MediaArchiveController($service, $auth)];
}

function ingestSharedSample(int $userId = 1): \Spora\Models\MediaAsset
{
    [$service] = buildSharingController();
    return $service->ingest(new MediaIngestRequest(
        bytes: 'hello',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: $userId,
        uploadSource: 'upload',
    ));
}