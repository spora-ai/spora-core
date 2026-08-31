<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\PrincipalContext;

function seedMediaArchiveSvcPrincipal(int $userId): int
{
    // FK on principals.user_id → users.id; seed the user first so the
    // raw insert doesn't trip the FK.
    seedMediaArchiveSvcUser($userId);
    return (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_USER,
        'user_id'    => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function seedMediaArchiveSvcUser(int $userId): void
{
    $pdo = Capsule::connection()->getPdo();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO users (id, email, password, username, verified, resettable, roles_mask, registered, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, 1, 1, 0, ?, ?, ?)',
    );
    $email = sprintf('masps-%d@example.com', $userId);
    $now = time();
    $stmt->execute([
        $userId,
        $email,
        password_hash('Password1!', PASSWORD_BCRYPT),
        $email,
        $now,
        date('Y-m-d H:i:s', $now),
        date('Y-m-d H:i:s', $now),
    ]);
}

function seedMediaArchiveSvcAgent(int $principalId, string $name): int
{
    return (int) Agent::create([
        'principal_id' => $principalId,
        'name'         => $name,
        'max_steps'    => 10,
        'is_active'    => 1,
    ])->id;
}

function seedMediaArchiveSvcAsset(?int $agentId, ?int $userId, ?int $principalId = null): MediaAsset
{
    $id = sprintf(
        '%08x-aaaa-bbbb-cccc-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffffffffffff),
    );
    return MediaAsset::create([
        'id'                            => $id,
        'asset_url'                     => '/api/v1/assets/' . $id . '.png',
        'storage_mode'                  => 'data_url',
        'media_type'                    => 'image',
        'mime_type'                     => 'image/png',
        'byte_size'                     => 1024,
        'agent_id'                      => $agentId,
        'user_id'                       => $userId,
        'principal_id'                  => $principalId,
        'asset_token'                   => bin2hex(random_bytes(16)),
        'migrated_from_inline_data_url' => false,
    ]);
}

test('isAssetInPrincipalScope returns true for an asset uploaded directly by the owner user', function (): void {
    $userId = 12345;
    seedMediaArchiveSvcUser($userId);
    $principalId = seedMediaArchiveSvcPrincipal($userId);

    $asset = seedMediaArchiveSvcAsset(agentId: null, userId: $userId, principalId: $principalId);
    $context = new PrincipalContext(
        principalId: $principalId,
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );

    $svc = new MediaArchiveService(makeIngestPipeline());
    expect($svc->isAssetInPrincipalScope($asset, $context, $userId))->toBeTrue();
});

test('isAssetInPrincipalScope returns true when the asset is attached to an agent of the same principal', function (): void {
    $userId = 12346;
    seedMediaArchiveSvcUser($userId);
    $principalId = seedMediaArchiveSvcPrincipal($userId);

    $agentId = seedMediaArchiveSvcAgent($principalId, 'mp-svc-sibling');
    $asset = seedMediaArchiveSvcAsset(agentId: $agentId, userId: null);
    $context = new PrincipalContext(
        principalId: $principalId,
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );

    $svc = new MediaArchiveService(makeIngestPipeline());
    expect($svc->isAssetInPrincipalScope($asset, $context, $userId))->toBeTrue();
});

test('isAssetInPrincipalScope returns false when the asset is attached to an agent of a different principal', function (): void {
    $userId = 12347;
    $otherUserId = 12348;
    seedMediaArchiveSvcUser($userId);
    seedMediaArchiveSvcUser($otherUserId);
    $callerPrincipalId = seedMediaArchiveSvcPrincipal($userId);
    $otherPrincipalId  = seedMediaArchiveSvcPrincipal($otherUserId);

    $otherAgentId = seedMediaArchiveSvcAgent($otherPrincipalId, 'mp-svc-other');
    $asset = seedMediaArchiveSvcAsset(agentId: $otherAgentId, userId: $otherUserId);
    $context = new PrincipalContext(
        principalId: $callerPrincipalId,
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );

    $svc = new MediaArchiveService(makeIngestPipeline());
    expect($svc->isAssetInPrincipalScope($asset, $context, $userId))->toBeFalse();
});

test('isAssetInPrincipalScope returns false when the asset has no agent_id and the user_id does not match', function (): void {
    $userId = 12349;
    seedMediaArchiveSvcUser($userId);
    $principalId = seedMediaArchiveSvcPrincipal($userId);
    $asset = seedMediaArchiveSvcAsset(agentId: null, userId: 99999);
    $context = new PrincipalContext(
        principalId: $principalId,
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );

    $svc = new MediaArchiveService(makeIngestPipeline());
    expect($svc->isAssetInPrincipalScope($asset, $context, $userId))->toBeFalse();
});

test('isAssetInPrincipalScope returns false when the PrincipalContext is cold (principalId=0)', function (): void {
    $userId = 12350;
    $principalId = seedMediaArchiveSvcPrincipal($userId);
    $asset = seedMediaArchiveSvcAsset(agentId: null, userId: $userId, principalId: $principalId);

    $cold = new PrincipalContext(
        principalId: 0,
        type: Principal::TYPE_USER,
        ownerUserId: null,
        runnerUserId: null,
    );

    $svc = new MediaArchiveService(makeIngestPipeline());
    expect($svc->isAssetInPrincipalScope($asset, $cold, null))->toBeFalse();
});

function makeIngestPipeline(): \Spora\Services\MediaArchive\MediaArchiveIngestPipeline
{
    $tmp = sys_get_temp_dir() . '/spora-masips-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new \Spora\Core\Paths(BASE_PATH);
    $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new \Spora\Services\DatabaseAssetStore(1024 * 1024);
    $local    = new \Spora\Services\LocalAssetStore($paths, $security, 1024 * 1024);
    $assetStore = new \Spora\Services\AutoAssetStore($database, $local, 1024);
    $logger = new \Psr\Log\NullLogger();

    return new \Spora\Services\MediaArchive\MediaArchiveIngestPipeline(
        new \Spora\Services\MediaArchive\MediaIngestDecoder(),
        new \Spora\Services\MediaArchive\MediaArchiveUrlResolver(
            new \Spora\Services\MediaArchive\RemoteMediaFetcher(
                new \Symfony\Component\HttpClient\MockHttpClient([]),
                $logger,
            ),
            new \Spora\Services\MediaArchive\MimeSniffer(),
            $logger,
        ),
        new \Spora\Services\MediaArchive\MimeSniffer(),
        new \Spora\Services\MediaArchive\MetadataExtractor($logger, false),
        $assetStore,
        \Tests\Support\MediaArchiveTestSupport::buildConverterRegistry(),
        new \Spora\Services\PrincipalService(new \Spora\Services\PrincipalResolver()),
    );
}
