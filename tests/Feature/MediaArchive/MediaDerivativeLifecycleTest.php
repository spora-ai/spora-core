<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Mockery;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\AssetController;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveRetention;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Tools\MediaTool;
use Tests\Support\MediaArchiveTestSupport;

/**
 * The lifecycle guarantees around auto-created `md` derivatives. Each
 * block covers a way the previous "the column is just another field"
 * model broke, and would break again if the derivative were treated as an
 * unrelated side-table.
 */
beforeEach(function (): void {
    // Every derivative here comes from the double, so the core producers
    // the shared builder self-registers must be out of the way or the
    // natural-key assertions would resolve against a different producer.
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(\Tests\Support\TextDerivativeProducer::class);
});

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * A real user id, since `media_assets.user_id` and `agents.principal_id`
 * are FK-backed.
 */
function lifecycleUserId(): int
{
    static $counter = 0;
    $counter++;
    return bootAuthLayer()->register(
        sprintf('lifecycle-%d-%s@example.com', $counter, bin2hex(random_bytes(4))),
        'Password1!',
        'Lc',
    );
}

/**
 * An agent owned by `$userId`, with `$retention` temp rows to enforce.
 */
function lifecycleAgentId(int $userId, int $retention = 0): int
{
    $config = \Spora\Models\LLMDriverConfiguration::create([
        'principal_id'              => null,
        'name'                      => 'Lifecycle Config ' . bin2hex(random_bytes(3)),
        'driver_class'              => \Spora\Drivers\AnthropicCompatibleDriver::class,
        'settings'                  => json_encode(['api_key' => 'test']),
        'is_global'                 => true,
        'is_default'                => true,
    ]);
    $agent = \Spora\Models\Agent::create([
        'principal_id'                => createUserPrincipalPublic($userId),
        'name'                        => 'Lifecycle Agent',
        'llm_driver_config_id'        => $config->id,
        'max_steps'                   => 10,
        'is_active'                   => true,
        'voice_message_retention_count' => $retention,
    ]);
    return (int) $agent->id;
}

/**
 * A `ToolConfigService` mock pinning the media tool's `scope` setting,
 * so the `agent`-scope branch of `assetInScope()` is the one under test
 * rather than the principal fallback.
 */
function lifecycleScopeConfig(string $scope): \Spora\Services\ToolConfigService
{
    $config = Mockery::mock(\Spora\Services\ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['scope' => $scope]);
    return $config;
}

/** A local-mode store so the on-disk assertions are meaningful. */
function lifecycleAssetStore(): AutoAssetStore
{
    $tmp = sys_get_temp_dir() . '/spora-deriv-lifecycle-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    return new AutoAssetStore(
        new DatabaseAssetStore(50 * 1024 * 1024),
        new LocalAssetStore(
            new Paths(BASE_PATH),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
        // Threshold 1 forces every store into `local` mode, which is the
        // only mode with bytes on disk to clean up.
        1,
    );
}

function lifecycleDerivativeService(AutoAssetStore $store): MediaDerivativeService
{
    return MediaArchiveTestSupport::buildDerivativeService($store, new \Psr\Log\NullLogger());
}

function seedLifecycleParent(
    MediaDerivativeService $derivatives,
    ?int $userId = null,
    ?int $agentId = null,
    bool $isTemporary = false,
    string $mime = 'application/pdf',
): MediaAsset {
    $parent = MediaAsset::create([
        'id'                  => testGenerateUuidV4(),
        'asset_url'           => '/api/v1/assets/' . testGenerateUuidV4() . '.pdf',
        'storage_mode'        => 'data_url',
        'media_type'          => 'document',
        'mime_type'           => $mime,
        'byte_size'           => 64,
        'user_id'             => $userId,
        'agent_id'            => $agentId,
        'is_temporary'        => $isTemporary,
        'asset_token'         => bin2hex(random_bytes(16)),
        'filename'            => 'doc.pdf',
        'payload'             => '%PDF-1.4 body',
        'migrated_from_inline_data_url' => false,
    ]);

    $derivative = $derivatives->ensureTextDerivative($parent);
    expect($derivative)->not->toBeNull();

    return MediaAsset::query()->find((string) $parent->id) ?? $parent;
}

describe('delete cascade', function (): void {
    test('deleting a parent removes the derivative row', function (): void {
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);
        $service = MediaArchiveTestSupport::buildService($store, derivatives: $derivatives);

        $parent = seedLifecycleParent($derivatives);
        $derivative = $derivatives->findTextDerivative($parent);
        expect($derivative)->not->toBeNull();
        expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(1);

        $service->delete((string) $parent->id);

        expect(MediaAsset::query()->find((string) $derivative->id))->toBeNull();
        expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
    });

    test('deleting a parent removes the derivative file from disk', function (): void {
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);
        $service = MediaArchiveTestSupport::buildService($store, derivatives: $derivatives);

        $parent = seedLifecycleParent($derivatives);
        $derivative = $derivatives->findTextDerivative($parent);
        expect($derivative)->not->toBeNull();

        $path = (new Paths(BASE_PATH))->storage('assets') . '/' . $derivative->asset_token . '.md';
        expect(is_file($path))->toBeTrue();

        $service->delete((string) $parent->id);

        expect(is_file($path))->toBeFalse();
    });

    test('list() no longer surfaces the orphaned derivative after the parent is deleted', function (): void {
        // The finding this guards: `list()` filters derivative rows with
        // `whereNotIn('id', <derivative ids>)`. The FK cascade removes the
        // join rows, so an orphan stops matching that filter and
        // reappears as a stray top-level library asset with no route back
        // to its source. This assertion is the actual regression — the
        // row-exists check above would pass with a dangling join row.
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);
        $service = MediaArchiveTestSupport::buildService($store, derivatives: $derivatives);

        $parent = seedLifecycleParent($derivatives);
        $derivativeId = (string) $derivatives->findTextDerivative($parent)?->id;
        expect($derivativeId)->not->toBe('');

        $ids = static fn(): array => $service->list(new ListMediaQuery(
            sort: ListMediaQuery::SORT_CREATED_DESC,
            page: 1,
            perPage: 100,
        ))->getCollection()->map(static fn(MediaAsset $a): string => (string) $a->id)->all();

        // Present-but-filtered while the join row exists.
        expect(in_array($derivativeId, $ids(), true))->toBeFalse();

        $service->delete((string) $parent->id);

        // Gone from the DB *and* from the listing.
        expect(MediaAsset::query()->find($derivativeId))->toBeNull();
        expect(in_array($derivativeId, $ids(), true))->toBeFalse();
    });

    test('deleting a parent with no derivatives is a no-op on the derivative side', function (): void {
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);
        $service = MediaArchiveTestSupport::buildService($store, derivatives: $derivatives);

        $parent = MediaAsset::create([
            'id'           => testGenerateUuidV4(),
            'asset_url'    => '/api/v1/assets/' . testGenerateUuidV4() . '.txt',
            'storage_mode' => 'data_url',
            'media_type'   => 'document',
            'mime_type'    => 'text/plain',
            'byte_size'    => 5,
            'asset_token'  => bin2hex(random_bytes(16)),
            'filename'     => 'note.txt',
            'payload'      => 'hello',
            'migrated_from_inline_data_url' => false,
        ]);

        $service->delete((string) $parent->id);

        expect(MediaAsset::query()->find((string) $parent->id))->toBeNull();
    });
});

describe('per-field inheritance — the two access failures and the storage leak', function (): void {
    test('a non-admin owner can read back an auto-created derivative over HTTP', function (): void {
        // `AssetController::canAccessAsset()` is
        // `isAdmin() || ownsDirectly() || ownsViaAgent()`, and
        // `ownsDirectly()` is `$asset->user_id === $userId`. Without the
        // `user_id` inheritance the operator who uploaded the PDF cannot
        // open the extraction of their own document.
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);
        $service = MediaArchiveTestSupport::buildService($store, derivatives: $derivatives);

        $userId = bootAuthLayer()->register(
            'lifecycle-owner-' . bin2hex(random_bytes(4)) . '@example.com',
            'Password1!',
            'Lo',
        );
        $parent = seedLifecycleParent($derivatives, userId: $userId);
        $derivative = $derivatives->findTextDerivative($parent);
        expect($derivative)->not->toBeNull();
        expect((int) $derivative->user_id)->toBe($userId);

        $controller = new AssetController(
            $service,
            new DatabaseAssetStore(50 * 1024 * 1024),
            new LocalAssetStore(
                new Paths(BASE_PATH),
                new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            // Non-admin on purpose: the admin branch bypasses
            // `ownsDirectly()` entirely, so an admin fixture would pass
            // even with no `user_id` inheritance at all.
            new class ($userId) extends \Spora\Auth\AuthService {
                public function __construct(private readonly int $uid) {}
                public function currentUserId(): int
                {
                    return $this->uid;
                }
                public function isAdmin(): bool
                {
                    return false;
                }
            },
        );

        $response = $controller->show($derivative->id . '.md');

        expect($response->getStatusCode())->toBe(200);
        expect((string) $response->headers->get('Content-Type'))->toContain('markdown');
    });

    test('a scope=agent agent can get_media its own auto-created derivative', function (): void {
        // `MediaTool::assetInScope()` is
        // `return (int) $asset->agent_id === $agentId;` for `scope=agent`.
        // Without the `agent_id` inheritance the agent that caused the
        // derivative to exist gets `ERR_ASSET_NOT_FOUND` from the tool
        // that was supposed to hand it the document's text.
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);
        $service = MediaArchiveTestSupport::buildService($store, derivatives: $derivatives);

        $agentId = lifecycleAgentId(lifecycleUserId());
        $parent = seedLifecycleParent($derivatives, agentId: $agentId);
        $derivative = $derivatives->findTextDerivative($parent);
        expect($derivative)->not->toBeNull();
        expect((int) $derivative->agent_id)->toBe($agentId);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            lifecycleScopeConfig('agent'),
        );
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => (string) $derivative->id],
                agentId: $agentId,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->not->toContain('Media asset not found.');
            expect($result->content)->toContain($derivative->id);
        } finally {
            $restore();
        }
    });

    test("a temporary parent's derivative is reachable by the retention sweep", function (): void {
        // `MediaArchiveRetention::findExcessTempIds()` filters
        // `user_id + agent_id + is_temporary` together. A derivative that
        // missed any one of the three never matches and grows without
        // bound — a permanent storage leak, not just a wrong flag.
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);

        $retention = 1;
        $userId = lifecycleUserId();
        $agentId = lifecycleAgentId($userId, $retention);
        $parent = seedLifecycleParent($derivatives, userId: $userId, agentId: $agentId, isTemporary: true);

        $derivative = $derivatives->findTextDerivative($parent);
        expect($derivative)->not->toBeNull();
        expect((bool) $derivative->is_temporary)->toBeTrue();
        expect((int) $derivative->user_id)->toBe($userId);
        expect((int) $derivative->agent_id)->toBe($agentId);

        // Seed an older temp row for the same (user, agent) pair so the
        // sweep has two candidates (the derivative and this one) against a
        // ceiling of 1.
        $oldTemp = MediaAsset::create([
            'id'                  => testGenerateUuidV4(),
            'asset_url'           => '/api/v1/assets/' . testGenerateUuidV4() . '.webm',
            'storage_mode'        => 'data_url',
            'media_type'          => 'audio',
            'mime_type'           => 'audio/webm',
            'byte_size'           => 8,
            'user_id'             => $userId,
            'agent_id'            => $agentId,
            'is_temporary'        => true,
            'asset_token'         => bin2hex(random_bytes(16)),
            'filename'            => 'older.webm',
            'payload'             => 'audio',
            'migrated_from_inline_data_url' => false,
        ]);

        $deleted = (new MediaArchiveRetention())->enforceTempRetention($userId, $agentId, (string) $parent->id);

        expect($deleted)->toBeGreaterThanOrEqual(2);
        // The derivative is gone, not just the parent and the older row.
        expect(MediaAsset::query()->find((string) $derivative->id))->toBeNull();
        expect(MediaAsset::query()->find((string) $oldTemp->id))->toBeNull();
    });
});

describe('refresh() rewrites the bytes', function (): void {
    test('re-producing the same natural key replaces the stored content', function (): void {
        // The `create_derivative` idempotency path: the natural key is
        // `(parent_id, format, producer_plugin, producer_operation)`, so
        // a re-render lands on `refresh()` rather than a new row. For an
        // `md` derivative, updating only `mime_type` / `byte_size` while
        // leaving `payload` and the on-disk file alone is silent text
        // divergence — the LLM keeps reading the first extraction, and
        // nothing anywhere reports a mismatch.
        $store = lifecycleAssetStore();
        $derivatives = lifecycleDerivativeService($store);

        $parent = MediaAsset::create([
            'id'           => testGenerateUuidV4(),
            'asset_url'    => '/api/v1/assets/' . testGenerateUuidV4() . '.pdf',
            'storage_mode' => 'data_url',
            'media_type'   => 'document',
            'mime_type'    => 'application/pdf',
            'byte_size'    => 64,
            'asset_token'  => bin2hex(random_bytes(16)),
            'filename'     => 'doc.pdf',
            'payload'      => '%PDF-1.4 body',
            'migrated_from_inline_data_url' => false,
        ]);

        $v1 = 'FIRST EXTRACTION';
        $v2 = 'SECOND, DIFFERENT EXTRACTION';

        $first = $derivatives->create(
            parent: $parent,
            output: new DerivativeOutput(bytes: $v1, mime: 'text/markdown'),
            format: 'md',
            producerPlugin: 'tests-text-derivative',
            producerOperation: 'text.extract',
        );
        expect($first)->not->toBeNull();

        $firstPath = (new Paths(BASE_PATH))->storage('assets') . '/' . $first->asset_token . '.md';
        expect(is_file($firstPath))->toBeTrue();
        expect(file_get_contents($firstPath))->toBe($v1);

        // Same natural key → same row, rewritten bytes.
        $second = $derivatives->create(
            parent: $parent,
            output: new DerivativeOutput(bytes: $v2, mime: 'text/markdown'),
            format: 'md',
            producerPlugin: 'tests-text-derivative',
            producerOperation: 'text.extract',
        );

        expect($second->id)->toBe($first->id);
        // One row, not two.
        expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(1);
        // The on-disk file is overwritten in place — a fresh token would
        // orphan the previous file on every re-render.
        expect($second->asset_token)->toBe($first->asset_token);
        expect(file_get_contents($firstPath))->toBe($v2);
        expect((int) $second->byte_size)->toBe(strlen($v2));
    });

    test('refresh() rewrites the data_url payload too', function (): void {
        // The other storage mode. Asserting only the local branch would
        // leave a test-fixture-shaped gap: in production an operator on
        // `asset_store.mode = data_url` never touches disk at all.
        $derivatives = MediaArchiveTestSupport::buildDerivativeService(
            MediaArchiveTestSupport::testAssetStore(),
        );

        $parent = MediaAsset::create([
            'id'           => testGenerateUuidV4(),
            'asset_url'    => '/api/v1/assets/' . testGenerateUuidV4() . '.pdf',
            'storage_mode' => 'data_url',
            'media_type'   => 'document',
            'mime_type'    => 'application/pdf',
            'byte_size'    => 64,
            'asset_token'  => bin2hex(random_bytes(16)),
            'filename'     => 'doc.pdf',
            'payload'      => '%PDF-1.4 body',
            'migrated_from_inline_data_url' => false,
        ]);

        $v1 = 'FIRST EXTRACTION';
        $v2 = 'SECOND EXTRACTION';
        $first = $derivatives->create(
            parent: $parent,
            output: new DerivativeOutput(bytes: $v1, mime: 'text/markdown'),
            format: 'md',
            producerPlugin: 'tests-text-derivative',
            producerOperation: 'text.extract',
        );
        expect($first)->not->toBeNull();
        expect($first->storage_mode)->toBe('data_url');
        expect($first->payload)->toBe($v1);

        $second = $derivatives->create(
            parent: $parent,
            output: new DerivativeOutput(bytes: $v2, mime: 'text/markdown'),
            format: 'md',
            producerPlugin: 'tests-text-derivative',
            producerOperation: 'text.extract',
        );

        expect($second->id)->toBe($first->id);
        $reloaded = MediaAsset::query()->find((string) $second->id);
        expect($reloaded)->not->toBeNull();
        expect($reloaded->payload)->toBe($v2);
    });
});
