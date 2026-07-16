<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaArchiveController;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * Ownership enforcement for DELETE /api/v1/media/{id}.
 *
 * B1 (review): previously, the destroy() controller accepted any
 * authenticated caller and deleted the row without checking that the
 * caller owned it. update() and refreshPublicToken() both gate behind
 * canEdit(); this test pins the same gate on destroy().
 */
test('DELETE returns 403 when the asset is owned by a different user', function (): void {
    [$ingesterCtrl, $service] = buildDestroyFixtures(new class extends AuthService {
        public function __construct()
        { /* no-op */
        }
        public function currentUserId(): int
        {
            return 1;
        }
        public function isAdmin(): bool
        {
            return false;
        }
    });
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'secret',
        mime: 'text/plain',
        filename: 'secret.txt',
        userId: 99,
        uploadSource: 'upload',
    ));

    [$controller] = buildDestroyFixtures(new class extends AuthService {
        public function __construct()
        { /* no-op */
        }
        public function currentUserId(): int
        {
            return 1;
        }
        public function isAdmin(): bool
        {
            return false;
        }
    });

    $resp = $controller->destroy($asset->id);
    expect($resp->getStatusCode())->toBe(403);
    expect(\Spora\Models\MediaAsset::query()->find($asset->id))->not->toBeNull();
});

test('DELETE returns 200 for the owner', function (): void {
    [$controller, $service] = buildDestroyFixtures(new class extends AuthService {
        public function __construct()
        { /* no-op */
        }
        public function currentUserId(): int
        {
            return 7;
        }
        public function isAdmin(): bool
        {
            return false;
        }
    });
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'mine',
        mime: 'text/plain',
        filename: 'mine.txt',
        userId: 7,
        uploadSource: 'upload',
    ));

    $resp = $controller->destroy($asset->id);
    expect($resp->getStatusCode())->toBe(200);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['deleted'])->toBeTrue();
    expect($payload['data']['id'])->toBe($asset->id);
    expect(\Spora\Models\MediaAsset::query()->find($asset->id))->toBeNull();
});

test('DELETE returns 200 for an admin even when the asset is owned by another user', function (): void {
    [, $service] = buildDestroyFixtures(new class extends AuthService {
        public function __construct()
        { /* no-op */
        }
        public function currentUserId(): int
        {
            return 7;
        }
        public function isAdmin(): bool
        {
            return false;
        }
    });
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'someone-elses',
        mime: 'text/plain',
        filename: 'elses.txt',
        userId: 99,
        uploadSource: 'upload',
    ));

    [$controller] = buildDestroyFixtures(new class extends AuthService {
        public function __construct()
        { /* no-op */
        }
        public function currentUserId(): int
        {
            return 1;
        }
        public function isAdmin(): bool
        {
            return true;
        }
    });

    $resp = $controller->destroy($asset->id);
    expect($resp->getStatusCode())->toBe(200);
    expect(\Spora\Models\MediaAsset::query()->find($asset->id))->toBeNull();
});

test('DELETE returns 404 when the asset does not exist', function (): void {
    [$controller] = buildDestroyFixtures(MediaArchiveTestSupport::buildAuth());
    $resp = $controller->destroy('00000000-0000-0000-0000-000000000000');
    expect($resp->getStatusCode())->toBe(404);
});

/**
 * @return array{0: MediaArchiveController, 1: \Spora\Services\MediaArchive\MediaArchiveService, 2?: MediaArchiveController}
 */
function buildDestroyFixtures(AuthService $auth): array
{
    $tmp = sys_get_temp_dir() . '/spora-destroy-' . bin2hex(random_bytes(4));
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
    return [
        new MediaArchiveController($service, $auth),
        $service,
    ];
}
