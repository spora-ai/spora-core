<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigOverrides;
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

afterEach(function (): void {
    // The group-cascade toggle is read from the process environment, and
    // the env vars are process-level — they outlive the test that set them.
    putenv('SPORA_TOOLS_GROUP_CASCADE_ENABLED');
    putenv('SPORA_CONFIG_PATH');
    unset($_ENV['SPORA_TOOLS_GROUP_CASCADE_ENABLED'], $_ENV['SPORA_CONFIG_PATH']);
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

/**
 * The runtime's own view of the settings cascade, with the group cascade
 * turned on — the shape an install reaches when the operator enables it.
 */
function mediaScopeMigrationRuntimeWithGroupCascade(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new NullLogger(),
        [MediaTool::class],
        new ToolConfigOverrides(
            principalService: new PrincipalService(new PrincipalResolver()),
            groupCascadeEnabled: true,
        ),
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

it('materialises an agent whose group principal holds a scope, because the runtime does not read it', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope group off');
    mediaScopeMigrationEnable($agentId);

    $groupPrincipalId = $this->makeGroupPrincipal($this->userId, 'Media scope group off');
    $this->toolConfig->putPrincipalSettings(MediaTool::class, $groupPrincipalId, ['scope' => 'principal']);

    // The runtime's own view, with the group cascade off — the shipped
    // default. The cascade consults the user-principal alone, so the
    // group's scope is not part of the resolution at all.
    expect($this->toolConfig->getEffectiveSettingsWithSource(MediaTool::class, $agentId, $this->userId)['scope']['source'])
        ->toBe('default');

    $this->migration->up();

    // The group scope governs nothing, so the agent is materialised
    // rather than left on the new default.
    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId)['scope'])->toBe('agent');
});

it('leaves an agent alone when the group cascade is on, because the group scope then governs', function (): void {
    putenv('SPORA_TOOLS_GROUP_CASCADE_ENABLED=true');
    $_ENV['SPORA_TOOLS_GROUP_CASCADE_ENABLED'] = 'true';

    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope group on');
    mediaScopeMigrationEnable($agentId);

    $groupPrincipalId = $this->makeGroupPrincipal($this->userId, 'Media scope group on');
    $this->toolConfig->putPrincipalSettings(MediaTool::class, $groupPrincipalId, ['scope' => 'principal']);

    // The runtime with the cascade on: the group's scope is consulted,
    // and it is the group that answers.
    expect(mediaScopeMigrationRuntimeWithGroupCascade()
        ->getEffectiveSettingsWithSource(MediaTool::class, $agentId, $this->userId)['scope']['source'])
        ->toBe('group');

    $this->migration->up();

    // The group scope governs, so the agent's own row must not shadow it.
    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe([]);
});

it('materialises when the group-cascade state cannot be read', function (): void {
    $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope unknown cascade');
    mediaScopeMigrationEnable($agentId);

    $groupPrincipalId = $this->makeGroupPrincipal($this->userId, 'Media scope unknown cascade');
    $this->toolConfig->putPrincipalSettings(MediaTool::class, $groupPrincipalId, ['scope' => 'principal']);

    // A config file the app cannot read: the state of the toggle is then
    // unknowable, and unknowable must not be read as "the group scope
    // governs".
    $broken = tempnam(sys_get_temp_dir(), 'spora-config-');
    file_put_contents($broken, "<?php\nthrow new RuntimeException('unreadable');\n");
    putenv('SPORA_CONFIG_PATH=' . $broken);
    $_ENV['SPORA_CONFIG_PATH'] = $broken;

    try {
        $this->migration->up();
    } finally {
        putenv('SPORA_CONFIG_PATH');
        unset($_ENV['SPORA_CONFIG_PATH']);
        @unlink($broken);
    }

    expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId)['scope'])->toBe('agent');
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

    it('rolls the scope back when the marker cannot be written', function (): void {
        $agentId = mediaScopeMigrationAgent($this->userId, 'Media scope unmarked');
        mediaScopeMigrationEnable($agentId);

        // A marker table the insert cannot satisfy: `prior_settings` is
        // NOT NULL and the row this agent gets carries no prior settings.
        // The scope is written first, so without a transaction the row
        // would survive without the marker `down()` needs to undo it.
        Capsule::schema()->dropIfExists('media_scope_materialisations');
        Capsule::schema()->create('media_scope_materialisations', static function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_id')->primary();
            $table->text('prior_settings');
            $table->timestamp('created_at')->nullable();
        });

        $thrown = null;
        try {
            $this->migration->up();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        // The scope write was rolled back with the marker write.
        expect($this->toolConfig->getRawAgentOverride(MediaTool::class, $agentId))->toBe([])
            ->and((int) Capsule::table('agent_tool_overrides')->where('agent_id', $agentId)->count())->toBe(0)
            // And the failure is not swallowed.
            ->and($thrown)->toBeInstanceOf(QueryException::class);
    });
});
