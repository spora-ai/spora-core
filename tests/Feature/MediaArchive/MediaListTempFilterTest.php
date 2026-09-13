<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Models\User;
use Spora\Services\MediaArchive\ListMediaQuery;
use Tests\Support\MediaArchiveTestSupport;

/**
 * Coverage for `?include_temporary=true|false` on the listing endpoint.
 *
 * The default `false` is the safety net — temp rows (e.g. voice
 * transcripts the upload controller just stamped `is_temporary=true` on)
 * stay out of the dashboard's permanent grid. The `?include_temporary=true`
 * override surfaces them so the operator's debug view can audit the
 * temp set end-to-end.
 */

beforeEach(function (): void {
    $tmp = sys_get_temp_dir() . '/spora-list-temp-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    // Materialise a user row + user-principal so the ingest pipeline's
    // PrincipalService::ensureUserPrincipal() chain resolves cleanly.
    Capsule::table('users')->insert([
        'id' => 1,
        'email' => 'list-temp@example.com',
        'password' => password_hash('Password1!', PASSWORD_BCRYPT),
        'username' => 'list-temp',
        'verified' => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
    ]);
    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type' => Principal::TYPE_USER,
        'user_id' => 1,
    ]);
    Capsule::table('agents')->insert([
        'principal_id' => $principalId,
        'name' => 'list-temp-agent',
    ]);
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-list-temp-*') ?: [] as $dir) {
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

function seedTempAsset(int $userId, ?int $agentId, bool $isTemp, string $suffix): MediaAsset
{
    $asset = new MediaAsset();
    $asset->id = bin2hex(random_bytes(8));
    $asset->user_id = $userId;
    $asset->agent_id = $agentId;
    $asset->is_temporary = $isTemp;
    $asset->asset_url = '/api/v1/assets/' . $asset->id . '-' . $suffix;
    $asset->storage_mode = 'local';
    $asset->save();
    return $asset;
}

test('list excludes temp rows by default', function (): void {
    $service = MediaArchiveTestSupport::buildService(
        new Spora\Services\AutoAssetStore(
            new Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new Spora\Services\LocalAssetStore(
                new Spora\Core\Paths(BASE_PATH),
                new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            1_048_576,
        ),
    );

    $agent = Agent::query()->first();
    seedTempAsset(1, (int) $agent->id, true, 'temp');
    seedTempAsset(1, (int) $agent->id, false, 'perm');

    // Default includeTemporary=false → only the permanent row survives.
    $page = $service->list(new ListMediaQuery(agentOwnerUserId: 1));
    expect($page->total())->toBe(1);
    $items = collect($page->items());
    expect($items->first()->is_temporary)->toBeFalse();
});

test('list includes temp rows when ?include_temporary=true', function (): void {
    $service = MediaArchiveTestSupport::buildService(
        new Spora\Services\AutoAssetStore(
            new Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new Spora\Services\LocalAssetStore(
                new Spora\Core\Paths(BASE_PATH),
                new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            1_048_576,
        ),
    );

    $agent = Agent::query()->first();
    seedTempAsset(1, (int) $agent->id, true, 'temp');
    seedTempAsset(1, (int) $agent->id, false, 'perm');

    $page = $service->list(new ListMediaQuery(agentOwnerUserId: 1, includeTemporary: true));
    expect($page->total())->toBe(2);
    $isTemp = collect($page->items())->pluck('is_temporary')->all();
    expect($isTemp)->toContain(true);
    expect($isTemp)->toContain(false);
});
