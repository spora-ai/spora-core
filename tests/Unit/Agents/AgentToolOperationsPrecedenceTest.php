<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Spora\Agents\Exceptions\ToolContractException;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Services\Agents\AgentToolInstanceResolver;
use Spora\Services\Agents\AgentToolOperationsResolver;
use Spora\Services\Agents\AgentToolOverrideResolver;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;
use Tests\Fixtures\StubAutoApproveOutputTool;
use Tests\Fixtures\StubPlainToolWithoutOperations;

/**
 * `agent_tool_operation_overrides` is the single source of truth for
 * per-operation tool configuration, and `default_requires_approval` has
 * exactly one meaning: whether the operator's *current* setting requires
 * approval. `requiresApprovalByDefault` is only the activation-time default a
 * tool author declares; once an operator stores a row, that row is the
 * configuration and is returned verbatim in both directions.
 *
 * The precedence rule is implemented in six places, and two of them disagree
 * on a `HasOperations`-less tool. These tests pin the agreement and the
 * divergence so neither is incidental.
 */
defined('PRECEDENCE_TEST_PASSWORD') || define('PRECEDENCE_TEST_PASSWORD', 'Password1!');

function precedenceAgent(): array
{
    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "prec-{$seq}@example.com", PRECEDENCE_TEST_PASSWORD);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Precedence Agent ' . $seq,
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    return [$agent->id, $userId];
}

function precedenceResolver(): AgentToolOperationsResolver
{
    $security   = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new ToolConfigService($security, new Logger('test'), [CalculatorTool::class]);
    $llmConfig  = new LLMConfigService($security, []);
    $instanceResolver = new AgentToolInstanceResolver();
    $overrideResolver = new AgentToolOverrideResolver(
        $toolConfig,
        $llmConfig,
        new LLMConfigPreferences(),
        $instanceResolver,
    );

    return new AgentToolOperationsResolver($instanceResolver, $overrideResolver);
}

/**
 * The precedence methods under test never touch the LLM driver; a bare factory
 * keeps the construction honest without dragging in a full driver mock.
 */
function precedenceOrchestrator(): Spora\Agents\Orchestrator
{
    $factory = Mockery::mock(Spora\Drivers\DriverFactory::class);

    return new Spora\Agents\Orchestrator($factory, new Spora\Agents\OrchestratorConfig());
}

function precedenceWriteOverride(int $agentId, string $toolClass, string $operation, ?bool $enabled, ?bool $requiresApproval): void
{
    // Upsert, so a test can walk the same operation through several states.
    Capsule::table('agent_tool_operation_overrides')->updateOrInsert(
        [
            'agent_id'   => $agentId,
            'tool_class' => $toolClass,
            'operation'  => $operation,
        ],
        [
            'enabled'                   => $enabled === null ? null : (int) $enabled,
            'default_requires_approval' => $requiresApproval === null ? null : (int) $requiresApproval,
            'updated_at'                => date('Y-m-d H:i:s'),
        ],
    );
}

// ---------------------------------------------------------------------------
// Round-trip fidelity: a stored override is the configuration, in both
// directions. Nothing may clamp an auto-approved operation back to requiring
// approval because its attribute says so.
// ---------------------------------------------------------------------------

it('honours an auto-approve override on an operation whose attribute requires approval', function (): void {
    [$agentId, $userId] = precedenceAgent();
    precedenceWriteOverride($agentId, StubAutoApproveOutputTool::class, 'default', null, false);

    $result = precedenceResolver()->getOperationOverride(
        $agentId,
        $userId,
        StubAutoApproveOutputTool::class,
        'default',
    );

    expect($result['default_requires_approval'])->toBe(0)
        ->and($result['effective_requires_approval'])->toBeFalse();
});

it('honours an approval override on an operation whose attribute does not require approval', function (): void {
    [$agentId, $userId] = precedenceAgent();
    precedenceWriteOverride($agentId, StubAutoApproveOutputTool::class, 'default', null, true);

    $result = precedenceResolver()->getOperationOverride(
        $agentId,
        $userId,
        StubAutoApproveOutputTool::class,
        'default',
    );

    expect($result['default_requires_approval'])->toBe(1)
        ->and($result['effective_requires_approval'])->toBeTrue();
});

it('falls back to the attribute default only when the override column is null', function (): void {
    [$agentId, $userId] = precedenceAgent();
    // `enabled` is set, `default_requires_approval` is not: the approval axis
    // must come from the attribute while the enabled axis comes from the row.
    precedenceWriteOverride($agentId, StubAutoApproveOutputTool::class, 'default', false, null);

    $result = precedenceResolver()->getOperationOverride(
        $agentId,
        $userId,
        StubAutoApproveOutputTool::class,
        'default',
    );

    expect($result['enabled'])->toBe(0)
        ->and($result['effective_enabled'])->toBeFalse()
        ->and($result['default_requires_approval'])->toBeNull()
        // StubAutoApproveOutputTool declares requiresApprovalByDefault: false.
        ->and($result['effective_requires_approval'])->toBeFalse();
});

it('persists a patched override exactly as given, with no clamping', function (): void {
    [$agentId, $userId] = precedenceAgent();
    $resolver = precedenceResolver();

    $resolver->patchOperationOverride(
        $agentId,
        $userId,
        StubAutoApproveOutputTool::class,
        'default',
        ['default_requires_approval' => false],
    );

    $row = AgentToolOperationOverride::where('agent_id', $agentId)
        ->where('tool_class', StubAutoApproveOutputTool::class)
        ->where('operation', 'default')
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->getRawOriginal('default_requires_approval'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Divergence: a HasOperations-less tool throws in the Orchestrator and
// returns `true` in the resolver. That difference is load-bearing, so it is
// pinned rather than left to a future consolidation to "fix".
// ---------------------------------------------------------------------------

it('the orchestrator throws and the resolver returns true for a tool without HasOperations', function (): void {
    [$agentId, $userId] = precedenceAgent();

    // A class_exists-positive, HasOperations-negative tool — the one input
    // both readers accept and answer differently.
    $orchestrator = precedenceOrchestrator();
    $tool = new StubPlainToolWithoutOperations();

    $method = new ReflectionMethod(Spora\Agents\Orchestrator::class, 'resolveRequiresApproval');
    $toolClass = StubPlainToolWithoutOperations::class;

    expect(fn() => $method->invoke($orchestrator, $tool, $toolClass, $agentId, []))
        ->toThrow(ToolContractException::class);

    $result = precedenceResolver()->getOperationOverride(
        $agentId,
        $userId,
        StubPlainToolWithoutOperations::class,
        'nonexistent',
    );

    // The resolver's safe default is "approval required", never "no approval".
    expect($result['effective_requires_approval'])->toBeTrue()
        ->and($result['effective_enabled'])->toBeTrue();
});

it('an unknown tool class also falls back to the safe default in the resolver', function (): void {
    [$agentId, $userId] = precedenceAgent();

    $result = precedenceResolver()->getOperationOverride(
        $agentId,
        $userId,
        'Spora\\Tools\\DoesNotExist',
        'op',
    );

    expect($result['effective_requires_approval'])->toBeTrue()
        ->and($result['effective_enabled'])->toBeTrue();
});

it('the orchestrator and the resolver agree on the states both can express', function (): void {
    [$agentId] = precedenceAgent();

    $orchestrator = precedenceOrchestrator();
    $approval = new ReflectionMethod(Spora\Agents\Orchestrator::class, 'resolveRequiresApproval');
    $enabled  = new ReflectionMethod(Spora\Agents\Orchestrator::class, 'isOperationEnabled');

    $tool = new StubAutoApproveOutputTool();
    $class = StubAutoApproveOutputTool::class;

    // 1. No row at all → the attribute default (auto-approve, enabled).
    expect($approval->invoke($orchestrator, $tool, $class, $agentId, ['action' => 'default']))->toBeFalse()
        ->and($enabled->invoke($orchestrator, $tool, 'default', $agentId))->toBeTrue();

    // 2. Both axes overridden to the opposite of the attribute.
    precedenceWriteOverride($agentId, $class, 'default', false, true);

    expect($approval->invoke($orchestrator, $tool, $class, $agentId, ['action' => 'default']))->toBeTrue()
        ->and($enabled->invoke($orchestrator, $tool, 'default', $agentId))->toBeFalse();

    // 3. And back again — the override is the configuration in both
    //    directions, with no clamping toward the attribute.
    precedenceWriteOverride($agentId, $class, 'default', true, false);

    expect($approval->invoke($orchestrator, $tool, $class, $agentId, ['action' => 'default']))->toBeFalse()
        ->and($enabled->invoke($orchestrator, $tool, 'default', $agentId))->toBeTrue();
});
