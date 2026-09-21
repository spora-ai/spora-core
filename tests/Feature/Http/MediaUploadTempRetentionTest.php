<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaUploadController;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MediaArchiveTestSupport;

beforeEach(function (): void {
    $tmp = sys_get_temp_dir() . '/spora-retention-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    // Seed the principal + agent pair the controller's principal/agent
    // chain will resolve against.
    Capsule::table('users')->insert([
        'id' => 1,
        'email' => 'retention-u1@example.com',
        'password' => password_hash('Password1!', PASSWORD_BCRYPT),
        'username' => 'retention-u1',
        'verified' => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
    ]);
    $user2 = (int) Capsule::table('users')->insertGetId([
        'email' => 'retention-u2@example.com',
        'password' => password_hash('Password1!', PASSWORD_BCRYPT),
        'username' => 'retention-u2',
        'verified' => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
    ]);
    Capsule::table('principals')->insert([
        'id' => 1,
        'type' => Principal::TYPE_USER,
        'user_id' => 1,
    ]);
    Capsule::table('principals')->insert([
        'id' => 2,
        'type' => Principal::TYPE_USER,
        'user_id' => $user2,
    ]);
    Capsule::table('agents')->insert([
        'id' => 1,
        'principal_id' => 1,
        'name' => 'agent-1',
        'voice_message_retention_count' => 5,
    ]);
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-retention-*') ?: [] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
});

function buildUploadController(int $userId = 1): MediaUploadController
{
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new Spora\Services\DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new Spora\Services\AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);
    $auth = new class ($userId) extends AuthService {
        public function __construct(private int $userId) {}
        public function currentUserId(): int
        {
            return $this->userId;
        }
        public function isAdmin(): bool
        {
            return true;
        }
    };
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $allowed = new MediaAllowedTypesService($registry, new Spora\Drivers\DriverFactory(
        new Psr\Log\NullLogger(),
        new Spora\Services\LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
        300,
    ));
    $sniffer = new MimeSniffer();
    return new MediaUploadController(
        $service,
        new Spora\Services\MediaArchive\MediaArchiveRetention(),
        $allowed,
        $auth,
        new PrincipalResolver(),
        $sniffer,
    );
}

function makeUploadRequest(bool $isTemporary, ?int $agentId): Request
{
    $tmp = tempnam(sys_get_temp_dir(), 'ret');
    file_put_contents($tmp, "hello");
    $req = Request::create('/api/v1/media', 'POST', [
        'is_temporary' => $isTemporary ? 'true' : 'false',
        'agent_id' => $agentId !== null ? (string) $agentId : '',
    ], files: [
        'file' => new UploadedFile($tmp, 'ret.txt', 'text/plain', null, true),
    ]);
    return $req;
}

test('retention=5 with 5 existing temp rows: 6th upload deletes the oldest one', function (): void {
    $controller = buildUploadController(1);
    // 5 pre-existing temp rows for (user 1, agent 1).
    for ($i = 0; $i < 5; $i++) {
        $asset = new MediaAsset();
        $asset->id = testGenerateUuidV4();
        $asset->user_id = 1;
        $asset->agent_id = 1;
        $asset->is_temporary = true;
        $asset->asset_url = '/api/v1/assets/' . $asset->id;
        $asset->storage_mode = 'local';
        $asset->created_at = Illuminate\Support\Carbon::now()->subMinutes(10 - $i);
        $asset->updated_at = $asset->created_at;
        $asset->save();
    }
    expect(MediaAsset::where('user_id', 1)->where('agent_id', 1)->where('is_temporary', true)->count())->toBe(5);

    $resp = $controller->store(makeUploadRequest(true, 1));
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);

    // Count returns to 5 — the oldest of the original 5 was purged,
    // the new one survives.
    expect(MediaAsset::where('user_id', 1)->where('agent_id', 1)->where('is_temporary', true)->count())->toBe(5);
});

test('retention=0 means manual cleanup only — no purge at ingest', function (): void {
    Capsule::table('agents')->where('id', 1)->update(['voice_message_retention_count' => 0]);
    $controller = buildUploadController(1);

    for ($i = 0; $i < 8; $i++) {
        $asset = new MediaAsset();
        $asset->id = testGenerateUuidV4();
        $asset->user_id = 1;
        $asset->agent_id = 1;
        $asset->is_temporary = true;
        $asset->asset_url = '/api/v1/assets/' . $asset->id;
        $asset->storage_mode = 'local';
        $asset->save();
    }

    $resp = $controller->store(makeUploadRequest(true, 1));
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);

    // 8 originals + 1 new = 9 — the 0 ceiling skipped the purge entirely.
    expect(MediaAsset::where('user_id', 1)->where('agent_id', 1)->where('is_temporary', true)->count())->toBe(9);
});

test('retention=3 with 10 existing: 11th upload deletes 8 oldest so total equals 3', function (): void {
    Capsule::table('agents')->where('id', 1)->update(['voice_message_retention_count' => 3]);
    $controller = buildUploadController(1);

    for ($i = 0; $i < 10; $i++) {
        $asset = new MediaAsset();
        $asset->id = testGenerateUuidV4();
        $asset->user_id = 1;
        $asset->agent_id = 1;
        $asset->is_temporary = true;
        $asset->asset_url = '/api/v1/assets/' . $asset->id;
        $asset->storage_mode = 'local';
        // Older rows first; newer ones last.
        $asset->created_at = Illuminate\Support\Carbon::now()->subMinutes(20 - $i);
        $asset->updated_at = $asset->created_at;
        $asset->save();
    }

    $resp = $controller->store(makeUploadRequest(true, 1));
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);

    // Final count = retention (3) — the 11 rows that existed before
    // the upload + the new one all collapsed to 3.
    expect(MediaAsset::where('user_id', 1)->where('agent_id', 1)->where('is_temporary', true)->count())->toBe(3);
});

test('retention purge respects user boundaries — user 2 does not see user 1 rows reaped', function (): void {
    Capsule::table('agents')->where('id', 1)->update(['voice_message_retention_count' => 5]);
    $controllerUser1 = buildUploadController(1);
    $controllerUser2 = buildUploadController(2);

    // 5 rows for user 1.
    for ($i = 0; $i < 5; $i++) {
        $asset = new MediaAsset();
        $asset->id = testGenerateUuidV4();
        $asset->user_id = 1;
        $asset->agent_id = 1;
        $asset->is_temporary = true;
        $asset->asset_url = '/api/v1/assets/' . $asset->id;
        $asset->storage_mode = 'local';
        $asset->save();
    }
    // 5 rows for user 2 — also at the ceiling.
    for ($i = 0; $i < 5; $i++) {
        $asset = new MediaAsset();
        $asset->id = testGenerateUuidV4();
        $asset->user_id = 2;
        $asset->agent_id = 1;
        $asset->is_temporary = true;
        $asset->asset_url = '/api/v1/assets/' . $asset->id;
        $asset->storage_mode = 'local';
        $asset->save();
    }

    $resp = $controllerUser1->store(makeUploadRequest(true, 1));
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);

    // User 1 has 5 temp rows (oldest reaped to fit new), user 2 still
    // has all 5 untouched.
    expect(MediaAsset::where('user_id', 1)->where('agent_id', 1)->where('is_temporary', true)->count())->toBe(5);
    expect(MediaAsset::where('user_id', 2)->where('agent_id', 1)->where('is_temporary', true)->count())->toBe(5);
});

test('retention purge does NOT run when is_temporary is false', function (): void {
    Capsule::table('agents')->where('id', 1)->update(['voice_message_retention_count' => 1]);
    $controller = buildUploadController(1);

    // 5 rows already at the limit.
    for ($i = 0; $i < 5; $i++) {
        $asset = new MediaAsset();
        $asset->id = testGenerateUuidV4();
        $asset->user_id = 1;
        $asset->agent_id = 1;
        $asset->is_temporary = true;
        $asset->asset_url = '/api/v1/assets/' . $asset->id;
        $asset->storage_mode = 'local';
        $asset->save();
    }

    // Upload as permanent — the existing temp rows survive.
    $resp = $controller->store(makeUploadRequest(false, 1));
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);

    expect(MediaAsset::where('user_id', 1)->where('agent_id', 1)->where('is_temporary', true)->count())->toBe(5);
});

test('upload without agent_id skips the retention purge entirely (no agent context)', function (): void {
    Capsule::table('agents')->where('id', 1)->update(['voice_message_retention_count' => 1]);
    $controller = buildUploadController(1);

    for ($i = 0; $i < 5; $i++) {
        $asset = new MediaAsset();
        $asset->id = testGenerateUuidV4();
        $asset->user_id = 1;
        $asset->agent_id = null;
        $asset->is_temporary = true;
        $asset->asset_url = '/api/v1/assets/' . $asset->id;
        $asset->storage_mode = 'local';
        $asset->save();
    }

    $resp = $controller->store(makeUploadRequest(true, null));
    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);

    // No agent → no purge path. All 5 originals plus the new upload
    // (now 6) survive.
    expect(MediaAsset::where('user_id', 1)->whereNull('agent_id')->where('is_temporary', true)->count())->toBe(6);
});
