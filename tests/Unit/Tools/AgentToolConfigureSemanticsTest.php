<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Services\AgentManifest;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\AgentTool;
use Spora\Tools\CalculatorTool;
use Spora\Tools\TimeTool;

defined('AGENT_TOOL_SEMANTICS_PASSWORD') || define('AGENT_TOOL_SEMANTICS_PASSWORD', 'Password1!');

/**
 * `configure_tools` enablement semantics, against the real settings service
 * and the real database.
 *
 * The pre-existing `AgentToolTest` cases drive the same path with a mocked
 * `AgentToolSettingsServiceInterface`, so they can only prove the planner
 * *called* `disableTool` — never that a tool actually came off an agent.
 * That gap is what let a reported "disable is a silent no-op" bug survive a
 * green suite, so these assert the observable end state: the `agent_tools`
 * row, the manifest the failing call itself returns, and an independent
 * `read_agent` afterwards.
 *
 * @return array{0: AgentTool, 1: AgentService, 2: AgentToolSettingsService, 3: int}
 */
function makeAgentToolWithRealSettings(): array
{
    $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $toolConfig = new ToolConfigService(
        new SecurityManager($key),
        new NullLogger(),
        [CalculatorTool::class, TimeTool::class],
    );
    $llmConfig      = new LLMConfigService(new SecurityManager($key), []);
    $toolSettings   = new AgentToolSettingsService($toolConfig, $llmConfig);
    $agentService   = new AgentService();
    $manifest       = new AgentManifest($toolSettings, null);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "agent-tool-semantics-{$seq}@example.com", AGENT_TOOL_SEMANTICS_PASSWORD);

    return [new AgentTool($agentService, $toolSettings, $manifest), $agentService, $toolSettings, $userId];
}

if (!function_exists('agentToolContext')) {
    /**
     * The `PrincipalContext` a real orchestrator call for a user-owned agent
     * would carry, for `execute(..., context: agentToolContext($userId))`.
     *
     * `configure_tools` and `read_agent` read the acting user from
     * `$context?->ownerUserId`, and both refuse outright when that is null
     * ("requires an authenticated user"), so the user each test creates its
     * agents with has to travel as the context
     * `PrincipalResolver::resolveForToolExecute()` builds from the agent's
     * own row: the user's principal, that user as owner, and — because a
     * task with no `trigger_user_id` falls back to the owner — the same id
     * as the runner.
     *
     * Guarded so the parallel runner, which may load several of these files
     * into one worker, does not redeclare it.
     */
    function agentToolContext(int $userId): PrincipalContext
    {
        return new PrincipalContext(
            principalId: createUserPrincipalPublic($userId),
            type: Spora\Models\Principal::TYPE_USER,
            ownerUserId: $userId,
            runnerUserId: $userId,
        );
    }
}

/**
 * The persisted `agent_tools` rows for an agent, as tool classes.
 *
 * @return list<string>
 */
function persistedToolsFor(int $agentId): array
{
    return Capsule::table('agent_tools')
        ->where('agent_id', $agentId)
        ->orderBy('tool_class')
        ->pluck('tool_class')
        ->all();
}

/**
 * One tool's entry out of a manifest's `tools[]`, or null when absent.
 *
 * @param  array<string, mixed> $manifest
 * @return array<string, mixed>|null
 */
function manifestTool(array $manifest, string $toolClass): ?array
{
    foreach ($manifest['tools'] as $tool) {
        if ($tool['tool_class'] === $toolClass) {
            return $tool;
        }
    }
    return null;
}

/**
 * @param  array<string, mixed> $manifest
 */
function countEnabledIn(array $manifest): int
{
    $n = 0;
    foreach ($manifest['tools'] as $t) {
        if ($t['enabled'] === true) {
            $n++;
        }
    }
    return $n;
}

describe('configure_tools — enablement is tri-state', function (): void {

    it('enables, then disables, and the tool is observably off afterwards', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Round Trip']);

        $fresh = $tool->execute(['action' => 'read_agent', 'agent_id' => $target->id], $target->id, context: agentToolContext($userId));
        expect(countEnabledIn($fresh->data))->toBe(0, 'a fresh agent has no tools enabled');

        $on = $tool->execute([
            'action'   => 'configure_tools',
            'agent_id' => $target->id,
            'tools'    => [
                ['tool_class' => CalculatorTool::class, 'enabled' => true],
                ['tool_class' => TimeTool::class, 'enabled' => true],
            ],
        ], $target->id, context: agentToolContext($userId));

        expect($on->success)->toBeTrue()
            ->and(countEnabledIn($on->data))->toBe(2)
            ->and(persistedToolsFor($target->id))->toHaveCount(2);

        $off = $tool->execute([
            'action'   => 'configure_tools',
            'agent_id' => $target->id,
            'tools'    => [['tool_class' => CalculatorTool::class, 'enabled' => false]],
        ], $target->id, context: agentToolContext($userId));

        // The call that does the disabling must report it, not echo a stale state.
        expect($off->success)->toBeTrue()
            ->and(manifestTool($off->data, CalculatorTool::class)['enabled'])->toBeFalse()
            ->and(countEnabledIn($off->data))->toBe(1)
            ->and(persistedToolsFor($target->id))->toBe([TimeTool::class]);

        // And a read that did not perform the write must agree.
        $read = $tool->execute(['action' => 'read_agent', 'agent_id' => $target->id], $target->id, context: agentToolContext($userId));
        expect(manifestTool($read->data, CalculatorTool::class)['enabled'])->toBeFalse()
            ->and($read->content)->toContain(CalculatorTool::class);
    });

    it('leaves enablement alone when `enabled` is absent, so a bare entry cannot grant a tool', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Bare Entry']);

        $result = $tool->execute([
            'action'   => 'configure_tools',
            'agent_id' => $target->id,
            'tools'    => [['tool_class' => CalculatorTool::class]],
        ], $target->id, context: agentToolContext($userId));

        // The regression this pins: a dropped or omitted `enabled` used to
        // default to true, so anything that lost the key — a provider
        // truncating it, a model omitting it — silently *enabled* a tool.
        expect($result->success)->toBeTrue()
            ->and(manifestTool($result->data, CalculatorTool::class)['enabled'])->toBeFalse()
            ->and(persistedToolsFor($target->id))->toBe([]);
    });

    it('does not disable an already-disabled tool differently from enabling it', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Idempotent Disable']);

        $first = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => false]],
        ], $target->id, context: agentToolContext($userId));
        $second = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => false]],
        ], $target->id, context: agentToolContext($userId));

        expect($first->success)->toBeTrue()
            ->and($second->success)->toBeTrue()
            ->and(persistedToolsFor($target->id))->toBe([])
            ->and(manifestTool($second->data, TimeTool::class)['enabled'])->toBeFalse();
    });

    it('treats an empty tools list as a no-op rather than a revoke-all', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Empty List']);

        $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => true]],
        ], $target->id, context: agentToolContext($userId));

        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id, 'tools' => [],
        ], $target->id, context: agentToolContext($userId));

        // Revoke-all is not a thing here; the skills used to claim it was.
        expect($result->success)->toBeTrue()
            ->and(persistedToolsFor($target->id))->toBe([TimeTool::class])
            ->and(manifestTool($result->data, TimeTool::class)['enabled'])->toBeTrue();
    });
});

describe('configure_tools — a non-boolean enablement flag is refused', function (): void {

    it('honours the strings "true" and "false" instead of refusing a working channel', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Stringified Booleans']);

        // A provider that flattens scalars into strings is a real channel here.
        // Refusing "false" would leave a model able to grant a tool but never
        // revoke one — a correctness bug traded for a capability hole.
        $on = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => 'true']],
        ], $target->id, context: agentToolContext($userId));
        expect($on->success)->toBeTrue()
            ->and(persistedToolsFor($target->id))->toBe([TimeTool::class]);

        // The original defect in the string form: a `(bool)` cast read this as
        // true, so the revocation enabled the tool instead of removing it.
        $off = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => 'false']],
        ], $target->id, context: agentToolContext($userId));
        expect($off->success)->toBeTrue()
            ->and(persistedToolsFor($target->id))->toBe([]);

        $read = $tool->execute(['action' => 'read_agent', 'agent_id' => $target->id], $target->id, context: agentToolContext($userId));
        expect(manifestTool($read->data, TimeTool::class)['enabled'])->toBeFalse();
    });

    it('accepts the strings case-insensitively and with surrounding whitespace', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'String Case']);

        $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => ' TRUE ']],
        ], $target->id, context: agentToolContext($userId));
        expect(persistedToolsFor($target->id))->toBe([TimeTool::class]);

        $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => 'False']],
        ], $target->id, context: agentToolContext($userId));
        expect(persistedToolsFor($target->id))->toBe([]);
    });

    it('refuses the strings that cannot be read as a flag without guessing', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Ambiguous Strings']);

        foreach (['yes', 'no', 'on', 'off', 'truthy', '1.5', []] as $value) {
            $result = $tool->execute([
                'action' => 'configure_tools', 'agent_id' => $target->id,
                'tools'  => [['tool_class' => TimeTool::class, 'enabled' => $value]],
            ], $target->id, context: agentToolContext($userId));

            expect($result->success)->toBeFalse();
        }

        expect(persistedToolsFor($target->id))->toBe([]);
    });

    it('accepts 0 and 1, matching the coercion the schedule tool already uses', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Numeric Flags']);

        $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => 1]],
        ], $target->id, context: agentToolContext($userId));
        expect(persistedToolsFor($target->id))->toBe([TimeTool::class]);

        $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => 0]],
        ], $target->id, context: agentToolContext($userId));
        expect(persistedToolsFor($target->id))->toBe([]);

        $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => '0']],
        ], $target->id, context: agentToolContext($userId));
        expect(persistedToolsFor($target->id))->toBe([]);
    });

    it('reads a blank string as "no change", not as a revocation', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Blank String']);

        $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => true]],
        ], $target->id, context: agentToolContext($userId));

        // The shared helper maps "" to false, but tri-state makes "no change"
        // representable here, so a malformed empty value must not revoke.
        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => '  ']],
        ], $target->id, context: agentToolContext($userId));

        expect($result->success)->toBeTrue()
            ->and(persistedToolsFor($target->id))->toBe([TimeTool::class]);
    });

    it('honours stringified per-operation flags too', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Stringified Op']);

        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [[
                'tool_class' => TimeTool::class,
                'enabled'    => 'true',
                'operations' => [
                    ['name' => 'now', 'enabled' => 'false'],
                    ['name' => 'format', 'auto_approve' => 'true'],
                ],
            ]],
        ], $target->id, context: agentToolContext($userId));

        $byName = [];
        foreach (manifestTool($result->data, TimeTool::class)['operations'] as $op) {
            $byName[$op['name']] = $op;
        }

        expect($result->success)->toBeTrue()
            ->and($byName['now']['enabled'])->toBeFalse()
            ->and($byName['format']['requires_approval'])->toBeFalse();
    });

    it('refuses null, which cannot be told apart from an absent key', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Null Flag']);

        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [['tool_class' => TimeTool::class, 'enabled' => null]],
        ], $target->id, context: agentToolContext($userId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('must be true or false')
            ->and(persistedToolsFor($target->id))->toBe([]);
    });

    it('refuses an ambiguous per-operation flag and names the index', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Op Flag']);

        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [[
                'tool_class' => TimeTool::class,
                'enabled'    => true,
                'operations' => [['name' => 'now', 'enabled' => 'maybe']],
            ]],
        ], $target->id, context: agentToolContext($userId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('operations[0][0]')
            ->and($result->content)->toContain("'enabled' must be true or false")
            ->and(persistedToolsFor($target->id))->toBe([]);
    });
});

describe('configure_tools — an unwritable target is not a success', function (): void {

    it('fails when the settings service reports the target is not visible', function (): void {
        // `enableTool` signals this by returning ['error' => 'NOT_FOUND'] rather
        // than throwing. Discarding that return is what let a success-shaped
        // response hide a write that never happened, so it is asserted here
        // against the caller's view rather than by asserting the call happened.
        $toolSettings = Mockery::mock(AgentToolSettingsServiceInterface::class);
        $toolSettings->shouldReceive('enableTool')->andReturn(['error' => 'NOT_FOUND']);
        $toolSettings->shouldNotReceive('disableTool');
        $toolSettings->shouldNotReceive('putOverride');
        $toolSettings->shouldNotReceive('patchOperationOverride');

        $planner = new AgentTool\ConfigurePlanner($toolSettings);
        $plan    = $planner->buildPlan([['tool_class' => TimeTool::class, 'enabled' => true]]);
        expect($plan)->toBeArray();

        $applied = $planner->apply(1, 1, $plan);

        expect($applied)->toBeInstanceOf(Spora\Tools\ValueObjects\ToolResult::class)
            ->and($applied->success)->toBeFalse()
            ->and($applied->content)->toContain('not visible to this user');
    });

    it('leaves nothing half-applied when the first step is unwritable', function (): void {
        $toolSettings = Mockery::mock(AgentToolSettingsServiceInterface::class);
        $toolSettings->shouldReceive('enableTool')
            ->once()
            ->with(1, 1, TimeTool::class)
            ->andReturn(['error' => 'NOT_FOUND']);
        // The visibility check is per-agent, so a later step could only fail the
        // same way. Bailing on the first is what keeps this from becoming a
        // partial write — a revocation landing while the grant ahead of it did not.
        $toolSettings->shouldNotReceive('disableTool');
        $toolSettings->shouldNotReceive('putOverride');
        $toolSettings->shouldNotReceive('patchOperationOverride');

        $planner = new AgentTool\ConfigurePlanner($toolSettings);
        $plan    = $planner->buildPlan([
            ['tool_class' => TimeTool::class, 'enabled' => true],
            ['tool_class' => CalculatorTool::class, 'enabled' => false],
        ]);

        expect($planner->apply(1, 1, $plan))->not->toBeNull();
    });

    it('proceeds normally when the write reports no error', function (): void {
        $toolSettings = Mockery::mock(AgentToolSettingsServiceInterface::class);
        $toolSettings->shouldReceive('enableTool')->once()->andReturn(['tool' => ['tool_class' => TimeTool::class]]);
        $toolSettings->shouldReceive('disableTool')->once();
        $toolSettings->shouldNotReceive('putOverride');
        $toolSettings->shouldNotReceive('patchOperationOverride');

        $planner = new AgentTool\ConfigurePlanner($toolSettings);
        $plan    = $planner->buildPlan([
            ['tool_class' => TimeTool::class, 'enabled' => true],
            ['tool_class' => CalculatorTool::class, 'enabled' => false],
        ]);

        expect($planner->apply(1, 1, $plan))->toBeNull();
    });
});

describe('configure_tools — operation names are validated', function (): void {

    it('refuses an operation the tool does not declare, rather than writing a dead row', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Typo Op']);

        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [[
                'tool_class' => TimeTool::class,
                'enabled'    => true,
                'operations' => [['name' => 'get_time', 'enabled' => false]],
            ]],
        ], $target->id, context: agentToolContext($userId));

        // The reported shape: success, a junk row persisted, and an empty
        // `overrides` — which reads to the caller as "nothing landed" while a
        // model believes it revoked an operation.
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain("names 'get_time', which is not an operation on")
            ->and($result->content)->toContain('now, format')
            ->and(Capsule::table('agent_tool_operation_overrides')->where('agent_id', $target->id)->count())->toBe(0)
            ->and(persistedToolsFor($target->id))->toBe([]);
    });

    it('lands a real per-operation disable, and shows it in the overrides audit trail', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Per Op Ok']);

        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [[
                'tool_class' => TimeTool::class,
                'enabled'    => true,
                'operations' => [
                    ['name' => 'now', 'enabled' => false],
                    ['name' => 'format', 'auto_approve' => true],
                ],
            ]],
        ], $target->id, context: agentToolContext($userId));

        $ops = manifestTool($result->data, TimeTool::class)['operations'];
        $byName = [];
        foreach ($ops as $op) {
            $byName[$op['name']] = $op;
        }

        expect($result->success)->toBeTrue()
            ->and($byName['now']['enabled'])->toBeFalse()
            ->and($byName['format']['requires_approval'])->toBeFalse()
            ->and($result->data['overrides'])->toHaveCount(2);
    });

    it('writes nothing at all when a later entry is refused', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Atomic']);

        $result = $tool->execute([
            'action' => 'configure_tools', 'agent_id' => $target->id,
            'tools'  => [
                ['tool_class' => TimeTool::class, 'enabled' => true],
                ['tool_class' => CalculatorTool::class, 'settings' => ['nope' => 'x']],
            ],
        ], $target->id, context: agentToolContext($userId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('is not a setting on')
            // Atomicity: the valid first entry must not have been applied either.
            ->and(persistedToolsFor($target->id))->toBe([]);
    });
});

describe('write_notes_overwrite — empty content', function (): void {

    it('refuses an empty replace instead of reporting "unchanged" as success', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Notes Empty']);

        $tool->execute(['action' => 'write_notes_overwrite', 'content' => 'KEEP ME'], $target->id, context: agentToolContext($userId));

        $result = $tool->execute(['action' => 'write_notes_overwrite', 'content' => ''], $target->id, context: agentToolContext($userId));

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('content is empty')
            ->and($result->content)->toContain('settings panel')
            ->and((string) Capsule::table('agents')->where('id', $target->id)->value('notes'))->toBe('KEEP ME');
    });

    it('still accepts whitespace, which is content', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Notes Space']);

        $tool->execute(['action' => 'write_notes_overwrite', 'content' => 'x'], $target->id, context: agentToolContext($userId));
        $result = $tool->execute(['action' => 'write_notes_overwrite', 'content' => ' '], $target->id, context: agentToolContext($userId));

        expect($result->success)->toBeTrue()
            ->and((string) Capsule::table('agents')->where('id', $target->id)->value('notes'))->toBe(' ');
    });

    it('keeps the empty-append no-op, which is deliberate', function (): void {
        [$tool, $agents, $settings, $userId] = makeAgentToolWithRealSettings();
        $target = $agents->createAgent($userId, ['name' => 'Notes Append']);

        $tool->execute(['action' => 'write_notes', 'content' => 'BASE'], $target->id, context: agentToolContext($userId));
        $empty = $tool->execute(['action' => 'write_notes', 'content' => ''], $target->id, context: agentToolContext($userId));
        $tail  = $tool->execute(['action' => 'write_notes', 'content' => 'tail'], $target->id, context: agentToolContext($userId));

        // An empty append is a no-op so repeated calls don't stack separators
        // or drift updated_at — a different situation from a replace.
        expect($empty->success)->toBeTrue()
            ->and($empty->content)->toContain('Notes unchanged')
            ->and($tail->success)->toBeTrue()
            ->and((string) Capsule::table('agents')->where('id', $target->id)->value('notes'))->toBe("BASE\n\ntail");
    });
});
