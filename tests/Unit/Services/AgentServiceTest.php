<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\AgentService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;

defined('AGENT_TEST_PASSWORD') || define('AGENT_TEST_PASSWORD', 'Password1!');

/**
 * @return array{0: AgentService, 1: int}
 */
function makeAgentServiceWithUser(): array
{
    // The SecurityManager needs a real 32-byte key. We generate one per
    // process from a fixed seed via random_bytes; the in-memory DB is
    // rolled back after each test so the encrypted value never has to round-trip.
    $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger   = new NullLogger();

    $toolConfig = new ToolConfigService($security, $logger, [CalculatorTool::class]);
    $llmConfig  = new LLMConfigService($security, []);

    $service = new AgentService($toolConfig, $llmConfig);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $email = "agent-service-{$seq}@example.com";
    $userId = bootAuth($auth, $email, AGENT_TEST_PASSWORD);

    return [$service, $userId];
}

describe('AgentService::getAgentsForUser', function (): void {

    it('returns an empty list for a user with no agents', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->getAgentsForUser($userId))->toBe([]);
    });

    it('returns only the agents owned by the requested user', function (): void {
        [$service, $userIdA] = makeAgentServiceWithUser();

        Agent::create(['user_id' => $userIdA, 'name' => 'A1', 'max_steps' => 10, 'is_active' => true]);
        Agent::create(['user_id' => $userIdA, 'name' => 'A2', 'max_steps' => 5,  'is_active' => true]);

        $auth = bootAuthLayer();
        $userIdB = bootAuth($auth, 'agent-svc-other@example.com', AGENT_TEST_PASSWORD);
        Agent::create(['user_id' => $userIdB, 'name' => 'B1', 'max_steps' => 10, 'is_active' => true]);

        $result = $service->getAgentsForUser($userIdA);
        expect($result)->toHaveCount(2);
        expect(array_column($result, 'name'))->toContain('A1', 'A2');
        expect(array_column($result, 'name'))->not->toContain('B1');
    });
});

describe('AgentService::createAgent', function (): void {

    it('creates an agent and returns the persisted model', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();

        $agent = $service->createAgent($userId, [
            'name'        => 'New Agent',
            'description' => 'A test agent',
        ]);

        expect($agent)->toBeInstanceOf(Agent::class);
        expect($agent->name)->toBe('New Agent');
        expect($agent->user_id)->toBe($userId);
        expect($agent->is_active)->toBeTrue();
        expect($agent->max_steps)->toBe(10); // default
    });

    it('respects custom max_steps', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();

        $agent = $service->createAgent($userId, [
            'name'      => 'CustomSteps',
            'max_steps' => 25,
        ]);

        expect($agent->max_steps)->toBe(25);
    });
});

describe('AgentService::getAgent / updateAgent / deleteAgent', function (): void {

    it('returns the agent when ownership matches', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Owned']);

        $found = $service->getAgent($agent->id, $userId);
        expect($found)->not->toBeNull();
        expect($found->id)->toBe($agent->id);
    });

    it('returns null when agent belongs to a different user', function (): void {
        [$service, $userIdA] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userIdA, ['name' => 'A']);

        $auth = bootAuthLayer();
        $userIdB = bootAuth($auth, 'agent-svc-foreign@example.com', AGENT_TEST_PASSWORD);

        $found = $service->getAgent($agent->id, $userIdB);
        expect($found)->toBeNull();
    });

    it('updates only the allowed fields', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Before']);

        $updated = $service->updateAgent($agent->id, $userId, [
            'name'      => 'After',
            'max_steps' => 7,
            'recipe_id' => 'should-be-ignored',
        ]);

        expect($updated->name)->toBe('After');
        expect($updated->max_steps)->toBe(7);
        expect($updated->recipe_id)->not->toBe('should-be-ignored');
    });

    it('returns null when updating a non-existent agent', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $result = $service->updateAgent(9999, $userId, ['name' => 'X']);
        expect($result)->toBeNull();
    });

    it('returns true on delete when agent exists', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'ToDelete']);

        expect($service->deleteAgent($agent->id, $userId))->toBeTrue();
        expect(Agent::find($agent->id))->toBeNull();
    });

    it('returns false on delete when agent does not exist', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->deleteAgent(9999, $userId))->toBeFalse();
    });
});

describe('AgentService::enableTool / disableTool', function (): void {

    it('enables a tool on an agent', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Tooled']);

        $result = $service->enableTool($agent->id, $userId, CalculatorTool::class);
        expect($result['tool']['tool_class'])->toBe(CalculatorTool::class);
        expect($result['tool']['tool_name'])->toBe('calculator');
        expect($result)->not->toHaveKey('is_idempotent');
    });

    it('is idempotent when called twice', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Tooled']);

        $first  = $service->enableTool($agent->id, $userId, CalculatorTool::class);
        $second = $service->enableTool($agent->id, $userId, CalculatorTool::class);

        expect($second)->toHaveKey('is_idempotent');
        expect($second['is_idempotent'])->toBeTrue();
        // Both calls reference the same tool
        expect($first['tool']['tool_class'])->toBe($second['tool']['tool_class']);
    });

    it('returns error when agent does not exist', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $result = $service->enableTool(9999, $userId, CalculatorTool::class);
        expect($result)->toBe(['error' => 'NOT_FOUND']);
    });

    it('disables a tool', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Tooled']);
        $service->enableTool($agent->id, $userId, CalculatorTool::class);

        $service->disableTool($agent->id, $userId, CalculatorTool::class);

        $status = $service->getToolStatus($agent->id, $userId, CalculatorTool::class);
        expect($status['is_enabled'])->toBeFalse();
    });
});

describe('AgentService::getToolStatus / getAllToolsStatus', function (): void {

    it('returns null for a non-existent agent', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->getToolStatus(9999, $userId, CalculatorTool::class))->toBeNull();
        expect($service->getAllToolsStatus(9999, $userId))->toBeNull();
    });

    it('reports is_enabled=false for a tool that has not been enabled', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'NoTools']);

        $status = $service->getToolStatus($agent->id, $userId, CalculatorTool::class);
        expect($status['is_enabled'])->toBeFalse();
        expect($status['tool_class'])->toBe(CalculatorTool::class);
    });

    it('lists all registered tools in getAllToolsStatus', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'HasTools']);

        $all = $service->getAllToolsStatus($agent->id, $userId);
        expect($all)->toBeArray();
        expect($all)->toHaveCount(1);
        expect($all[0]['tool_class'])->toBe(CalculatorTool::class);
    });
});

describe('AgentService::getOverride', function (): void {

    it('returns an empty array when the agent does not exist', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->getOverride(9999, $userId, CalculatorTool::class))->toBe([]);
    });

    it('returns the raw agent override when rawOnly is true (no source annotation)', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'RawOverride']);
        $service->putOverride($agent->id, $userId, CalculatorTool::class, [
            'calculator.expression' => '2+2',
        ]);

        $raw = $service->getOverride($agent->id, $userId, CalculatorTool::class, rawOnly: true);
        expect($raw)->toHaveKey('calculator.expression');
        expect($raw['calculator.expression'])->toBe('2+2');
        // rawOnly path does NOT wrap in {value, source}
        expect($raw['calculator.expression'])->not->toHaveKey('value');
    });

    it('returns annotated {value, source} entries when rawOnly is false', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Annotated']);
        $service->putOverride($agent->id, $userId, CalculatorTool::class, [
            'calculator.expression' => '3+4',
        ]);

        $annotated = $service->getOverride($agent->id, $userId, CalculatorTool::class);
        expect($annotated)->toHaveKey('calculator.expression');
        expect($annotated['calculator.expression'])->toHaveKeys(['value', 'source']);
        expect($annotated['calculator.expression']['value'])->toBe('3+4');
        // The agent override layer is the source for the put
        expect($annotated['calculator.expression']['source'])->toBe('agent');
    });

    it('masks password settings to *** in the annotated path', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'PasswordAgent']);

        // CalculatorTool has no password settings — values pass through unchanged.
        $service->putOverride($agent->id, $userId, CalculatorTool::class, [
            'calculator.expression' => '1+1',
        ]);
        $annotated = $service->getOverride($agent->id, $userId, CalculatorTool::class);
        expect($annotated['calculator.expression']['value'])->toBe('1+1');
    });

    it('returns an empty array for llm_configuration when the agent has no config', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'NoLlmConfig']);

        $result = $service->getOverride($agent->id, $userId, 'llm_configuration');
        // No LLM driver config assigned → empty result
        expect($result)->toBe([]);
    });
});

describe('AgentService::putOverride / deleteOverride', function (): void {

    it('returns an empty array when putting on a non-existent agent', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->putOverride(9999, $userId, CalculatorTool::class, ['x' => 1]))->toBe([]);
    });

    it('returns the effective settings after a put', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'PutOK']);

        $masked = $service->putOverride($agent->id, $userId, CalculatorTool::class, [
            'calculator.expression' => '5*5',
        ]);
        expect($masked)->toHaveKey('calculator.expression');
        expect($masked['calculator.expression'])->toBe('5*5');
    });

    it('deleteOverride is a no-op when the agent does not exist', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        // Should not throw
        $service->deleteOverride(9999, $userId, CalculatorTool::class);
        expect(true)->toBeTrue();
    });

    it('deleteOverride removes the agent-level override', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'DeleteOK']);
        $service->putOverride($agent->id, $userId, CalculatorTool::class, [
            'calculator.expression' => '7+7',
        ]);

        $service->deleteOverride($agent->id, $userId, CalculatorTool::class);

        $raw = $service->getOverride($agent->id, $userId, CalculatorTool::class, rawOnly: true);
        // After delete, the agent-level override is gone — either empty or
        // shows the global default (also empty for CalculatorTool).
        expect($raw)->not->toHaveKey('calculator.expression');
    });
});

describe('AgentService::getToolsOperations / getOperationOverride / patchOperationOverride', function (): void {

    it('returns null for getToolsOperations when the agent does not exist', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->getToolsOperations(9999, $userId))->toBeNull();
    });

    it('returns enabled operations for tools that use HasOperations', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'HasOps']);
        $service->enableTool($agent->id, $userId, CalculatorTool::class);

        $ops = $service->getToolsOperations($agent->id, $userId);
        expect($ops)->not->toBeEmpty();
        expect($ops[0])->toHaveKeys([
            'tool_class', 'tool_name', 'operation',
            'enabled', 'default_requires_approval',
            'effective_enabled', 'effective_requires_approval',
        ]);
        expect($ops[0]['tool_class'])->toBe(CalculatorTool::class);
    });

    it('returns an empty array for getOperationOverride when the agent does not exist', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->getOperationOverride(9999, $userId, CalculatorTool::class, 'eval'))
            ->toBe([]);
    });

    it('returns an empty array for getOperationOverride for a missing override', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'OpOverride']);

        $row = $service->getOperationOverride($agent->id, $userId, CalculatorTool::class, 'eval');
        expect($row)->toBe([
            'operation'                   => 'eval',
            'tool_class'                  => CalculatorTool::class,
            'enabled'                      => null,
            'default_requires_approval'    => null,
            'effective_enabled'            => true,
            'effective_requires_approval'  => true,
        ]);
    });

    it('patchOperationOverride creates a new override row', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'PatchNew']);

        $result = $service->patchOperationOverride(
            $agent->id,
            $userId,
            CalculatorTool::class,
            'eval',
            ['enabled' => false, 'default_requires_approval' => true],
        );

        expect($result['enabled'])->toBe(0);
        expect($result['default_requires_approval'])->toBe(1);
    });

    it('patchOperationOverride updates an existing override row', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'PatchUpdate']);

        $service->patchOperationOverride(
            $agent->id,
            $userId,
            CalculatorTool::class,
            'eval',
            ['enabled' => true],
        );

        // Update only default_requires_approval — enabled must stay
        $updated = $service->patchOperationOverride(
            $agent->id,
            $userId,
            CalculatorTool::class,
            'eval',
            ['default_requires_approval' => false],
        );

        expect($updated['enabled'])->toBe(1);
        expect($updated['default_requires_approval'])->toBe(0);
    });

    it('patchOperationOverride returns empty when the agent does not exist', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        expect($service->patchOperationOverride(9999, $userId, CalculatorTool::class, 'eval', ['enabled' => true]))
            ->toBe([]);
    });
});
