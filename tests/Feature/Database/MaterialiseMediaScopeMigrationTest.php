<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\MediaTool;

/**
 * Migration 0105 — the media tool's `scope` setting keeps the reach it had.
 *
 * The default moved from `agent` to `principal`, and an agent that had the
 * `media` tool enabled before the move can end up with no stored scope
 * anywhere: enabling a tool materialises the schema default into the
 * agent's own override row only under a condition, and the other branch
 * leaves the agent on the shipped default.
 *
 * The migration materialises `scope: 'agent'` for exactly those agents —
 * the ones whose effective scope resolves from the schema default — and
 * leaves every agent whose scope comes from a layer alone.
 */
beforeEach(function (): void {
    $this->userId = 51601;

    $this->principalId = createUserPrincipalPublic($this->userId);

    $this->toolConfig = new ToolConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new NullLogger(),
        [MediaTool::class],
    );

    $this->migration = require __DIR__ . '/../../../database/migrations/0105_materialise_the_media_scope_for_pre_existing_agents.php';
});

/**
 * An agent of the user's principal.
 */
function mediaScopeMigrationAgent(int $userId, string $name): int
{
    return Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => $name,
        'is_active'    => true,
    ])->id;
}

/**
 * The agent has the `media` tool enabled — the state an install is in
 * before the scope default moves.
 */
function mediaScopeMigrationEnable(int $agentId): void
{
    Capsule::table('agent_tools')->insert([
        'agent_id'   => $agentId,
        'tool_class' => MediaTool::class,
        'tool_name'  => 'media',
    ]);
}

function mediaScopeMigrationContext(int $userId): PrincipalContext
{
    return new PrincipalContext(
        principalId: createUserPrincipalPublic($userId),
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );
}

it('keeps the reach an agent had before the scope default moved', function (): void {
    $callerAgent  = mediaScopeMigrationAgent($this->userId, 'Media scope caller');
    $siblingAgent = mediaScopeMigrationAgent($this->userId, 'Media scope sibling');
    mediaScopeMigrationEnable($callerAgent);
    mediaScopeMigrationEnable($siblingAgent);

    // The sibling's asset: reachable under the principal scope, out of
    // reach under the agent scope.
    $siblingAsset = seedMediaAsset(agentId: $siblingAgent, userId: $this->userId);

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolNonAdminAuth(),
        $this->toolConfig,
    );
    try {
        $before = $tool->execute(
            ['action' => 'get_media', 'asset_id' => $siblingAsset->id],
            agentId: $callerAgent,
            context: mediaScopeMigrationContext($this->userId),
        );

        // Before the migration the shipped default governs, and the default
        // is now the wider one — so the sibling's asset is in reach.
        expect([
            'effective_scope'   => $this->toolConfig->getEffectiveSettings(MediaTool::class, $callerAgent, $this->userId)['scope'],
            'sibling_reachable' => $before->success,
        ])->toBe([
            'effective_scope'   => 'principal',
            'sibling_reachable' => true,
        ]);

        $this->migration->up();

        $after = $tool->execute(
            ['action' => 'get_media', 'asset_id' => $siblingAsset->id],
            agentId: $callerAgent,
            context: mediaScopeMigrationContext($this->userId),
        );

        // Both halves of the claim in one assertion: the scope the agent
        // runs with, and what that scope lets it reach.
        expect([
            'effective_scope'   => $this->toolConfig->getEffectiveSettings(MediaTool::class, $callerAgent, $this->userId)['scope'],
            'sibling_reachable' => $after->success,
        ])->toBe([
            'effective_scope'   => 'agent',
            'sibling_reachable' => false,
        ]);

        expect($after->content)->toContain('Media asset not found');
    } finally {
        $restore();
    }
});

it('leaves an agent whose global scope governs alone', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope global');
    mediaScopeMigrationEnable($agentId);

    $this->toolConfig->putGlobalSettings(MediaTool::class, ['scope' => 'principal']);

    $this->migration->up();

    // The global layer keeps governing — and the agent's own row did not
    // gain an `agent` that would shadow it.
    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe([])
        ->and($this->toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $this->userId)['scope'])
        ->toBe('principal');
});

it('leaves an agent whose principal scope governs alone', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope principal');
    mediaScopeMigrationEnable($agentId);

    $this->toolConfig->putPrincipalSettings(MediaTool::class, $this->principalId, ['scope' => 'principal']);

    $this->migration->up();

    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe([])
        ->and($this->toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $this->userId)['scope'])
        ->toBe('principal');
});

it('leaves an agent whose own scope is set alone', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope own');
    mediaScopeMigrationEnable($agentId);

    $this->toolConfig->putAgentOverride(MediaTool::class, $agentId, ['scope' => 'principal']);

    $this->migration->up();

    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId)['scope'])->toBe('principal')
        ->and($this->toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $this->userId)['scope'])
        ->toBe('principal');
});

it('materialises the scope into an agent whose override row holds none', function (): void {
    // The state `enableTool()` leaves behind when it skips the
    // materialisation: the agent has an override row, and no scope in it.
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope empty row');
    mediaScopeMigrationEnable($agentId);

    $this->toolConfig->putAgentOverride(MediaTool::class, $agentId, []);

    expect((int) Capsule::table('agent_tool_overrides')->where('agent_id', $agentId)->count())->toBe(1)
        ->and($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe([]);

    $this->migration->up();

    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId)['scope'])->toBe('agent')
        ->and($this->toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $this->userId)['scope'])
        ->toBe('agent');
});

it('leaves an agent without the media tool alone', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope without tool');

    $this->migration->up();

    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe([]);
});

it('changes nothing when it runs twice', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope twice');
    mediaScopeMigrationEnable($agentId);

    $this->migration->up();
    $afterFirst = $this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId);

    $this->migration->up();

    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe($afterFirst)
        ->and((int) Capsule::table('media_scope_materialisations')->where('agent_id', $agentId)->count())
        ->toBe(1);
});

it('takes back only the scope it wrote', function (): void {
    $materialisedAgent = mediaScopeMigrationAgent($this->userId, 'Media scope materialised');
    $operatorAgent     = mediaScopeMigrationAgent($this->userId, 'Media scope operator');
    mediaScopeMigrationEnable($materialisedAgent);
    mediaScopeMigrationEnable($operatorAgent);

    $this->toolConfig->putAgentOverride(MediaTool::class, $operatorAgent, ['scope' => 'agent']);

    $this->migration->up();
    $this->migration->down();

    // The row the migration wrote is gone — not merely emptied, because
    // the agent had no row before the migration ran.
    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $materialisedAgent))->toBe([])
        ->and((int) Capsule::table('agent_tool_overrides')->where('agent_id', $materialisedAgent)->count())->toBe(0)
        // The operator's own scope is untouched.
        ->and($this->toolConfig->getRawAgentOverride(MediaTool::class, $operatorAgent)['scope'])->toBe('agent');
});

it('leaves no override row behind for an agent that had none', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope no row');
    mediaScopeMigrationEnable($agentId);

    expect((int) Capsule::table('agent_tool_overrides')->where('agent_id', $agentId)->count())->toBe(0);

    $this->migration->up();

    expect((int) Capsule::table('agent_tool_overrides')->where('agent_id', $agentId)->count())->toBe(1);

    $this->migration->down();

    // The row this migration created is deleted again. A row that held
    // empty settings would look the same to the reader, and would leave
    // behind a row that did not exist before.
    expect((int) Capsule::table('agent_tool_overrides')->where('agent_id', $agentId)->count())->toBe(0);
});

it('keeps a row that existed before the migration', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope empty row rollback');
    mediaScopeMigrationEnable($agentId);

    $this->toolConfig->putAgentOverride(MediaTool::class, $agentId, []);

    $this->migration->up();
    $this->migration->down();

    // The row existed before the migration, so the rollback restores it
    // rather than deleting it — the two cases the marker has to tell
    // apart.
    expect((int) Capsule::table('agent_tool_overrides')->where('agent_id', $agentId)->count())->toBe(1)
        ->and($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe([]);
});

describe('a write the database refuses', function (): void {
    beforeEach(function (): void {
        // DDL mid-test → a database of its own, because a schema change
        // inside the suite's transaction is not portable.
        TestDatabaseFactory::freshDatabase();
    });

    it('stops the migration instead of leaving the agent widened', function (): void {
        $blocked = mediaScopeMigrationAgent($this->userId, 'Media scope unwritable');
        mediaScopeMigrationEnable($blocked);

        // A row that already holds the blob the migration writes, under a
        // unique index over the column: the migration's own insert now
        // collides. Any write the database refuses would stand in here.
        $holder = mediaScopeMigrationAgent($this->userId, 'Media scope holder');
        Capsule::connection()->statement(
            'CREATE UNIQUE INDEX uq_media_scope_settings ON agent_tool_overrides (settings)',
        );
        $this->toolConfig->putAgentOverride(MediaTool::class, $holder, ['scope' => 'agent']);

        $thrown = null;
        try {
            $this->migration->up();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        // The migration does not report success.
        expect($thrown)->toBeInstanceOf(QueryException::class)
            // And it left nothing behind: no scope stored, and no marker
            // claiming the agent was dealt with.
            ->and($this->toolConfig->getRawAgentOverride(MediaTool::class, $blocked))->toBe([])
            ->and((int) Capsule::table('agent_tool_overrides')->where('agent_id', $blocked)->count())->toBe(0)
            ->and((int) Capsule::table('media_scope_materialisations')->where('agent_id', $blocked)->count())->toBe(0);
    });
});
