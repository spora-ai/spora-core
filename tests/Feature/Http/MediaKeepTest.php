<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\KeepMediaController;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveRetention;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MediaArchiveTestSupport;
use Tests\Support\TempDirectory;

beforeEach(function (): void {
    $this->storageDir = TempDirectory::create('spora-keep-');
    putenv("SPORA_STORAGE_DIR={$this->storageDir}");
    $_ENV['SPORA_STORAGE_DIR']    = $this->storageDir;
    $_SERVER['SPORA_STORAGE_DIR'] = $this->storageDir;
});

afterEach(function (): void {
    TempDirectory::remove((string) $this->storageDir);
});

test('the cleanup removes the directory it was given, not every directory sharing the prefix', function (): void {
    // A directory the test did not create must survive the cleanup: the
    // shared temp directory is not the test's to wipe.
    $decoy = sys_get_temp_dir() . '/spora-keep-decoy-' . bin2hex(random_bytes(4));
    mkdir($decoy, 0755, true);
    file_put_contents($decoy . '/marker.txt', 'not mine');

    try {
        TempDirectory::remove((string) $this->storageDir);

        expect(is_dir($this->storageDir))->toBeFalse()
            ->and(file_get_contents($decoy . '/marker.txt'))->toBe('not mine');
    } finally {
        @unlink($decoy . '/marker.txt');
        @rmdir($decoy);
    }
});

function seedKeepAsset(int $userId, bool $isTemp, string $suffix): MediaAsset
{
    $asset = new MediaAsset();
    $asset->id = testGenerateUuidV4();
    $asset->user_id = $userId;
    $asset->is_temporary = $isTemp;
    $asset->asset_url = '/api/v1/assets/' . $asset->id . '-' . $suffix;
    $asset->storage_mode = 'local';
    $asset->save();
    return $asset;
}

function buildKeepController(?AuthService $auth): KeepMediaController
{
    $service = MediaArchiveTestSupport::buildService(
        new Spora\Services\AutoAssetStore(
            new Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new Spora\Services\LocalAssetStore(
                new Paths(BASE_PATH),
                new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            1_048_576,
        ),
    );
    $auth ??= buildAdminAuth();
    return new KeepMediaController($service, $auth, new MediaArchiveRetention());
}

function buildAdminAuth(): AuthService
{
    return new class extends AuthService {
        public function __construct() {}
        public function currentUserId(): int
        {
            return 999;
        }
        public function isAdmin(): bool
        {
            return true;
        }
    };
}

function buildOwnerAuth(int $userId): AuthService
{
    return new class ($userId) extends AuthService {
        public function __construct(private int $userId) {}
        public function currentUserId(): int
        {
            return $this->userId;
        }
        public function isAdmin(): bool
        {
            return false;
        }
    };
}

function buildStrangerAuth(): AuthService
{
    return new class extends AuthService {
        public function __construct() {}
        public function currentUserId(): int
        {
            return 888;
        }
        public function isAdmin(): bool
        {
            return false;
        }
    };
}

test('admin can keep a temp row — flips is_temporary=false and returns the serialised asset', function (): void {
    $asset = seedKeepAsset(1, true, 'temp');
    $controller = buildKeepController(null);

    $resp = $controller->keep($asset->id);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['is_temporary'])->toBeFalse();
    expect($payload['data']['id'])->toBe($asset->id);

    $reloaded = MediaAsset::find($asset->id);
    expect($reloaded->is_temporary)->toBeFalse();
});

test('owner can keep their own temp row', function (): void {
    $asset = seedKeepAsset(42, true, 'mine');
    $controller = buildKeepController(buildOwnerAuth(42));

    $resp = $controller->keep($asset->id);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $reloaded = MediaAsset::find($asset->id);
    expect((bool) $reloaded->is_temporary)->toBeFalse();
});

test('stranger gets 403 when the row belongs to another user', function (): void {
    $asset = seedKeepAsset(1, true, 'theirs');
    $controller = buildKeepController(buildStrangerAuth());

    $resp = $controller->keep($asset->id);

    expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    expect((bool) MediaAsset::find($asset->id)->is_temporary)->toBeTrue();
});

test('unknown id returns 404', function (): void {
    $controller = buildKeepController(null);

    $resp = $controller->keep('does-not-exist');

    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

test('keep is idempotent — calling on a non-temp row returns 200 without re-saving', function (): void {
    $asset = seedKeepAsset(1, false, 'already-permanent');
    $originalUpdatedAt = $asset->updated_at?->toIso8601String();
    $controller = buildKeepController(null);

    $resp = $controller->keep($asset->id);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $payload = json_decode($resp->getContent(), true);
    expect($payload['data']['is_temporary'])->toBeFalse();
    $reloaded = MediaAsset::find($asset->id);
    expect($reloaded->updated_at?->toIso8601String())->toBe($originalUpdatedAt);
});
