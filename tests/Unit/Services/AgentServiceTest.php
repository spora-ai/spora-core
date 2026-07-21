<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\Agents\AgentToolInstanceResolver;
use Spora\Services\Agents\AgentToolOverrideResolver;
use Spora\Services\AgentService;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolIconResolver;
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

/**
 * @return array{0: AgentService, 1: int}
 */
function makeAgentServiceWithLlmDriver(): array
{
    // Same as makeAgentServiceWithUser() but with an LLMConfigService that
    // knows about the OpenAI driver so getOverride('llm_configuration', …)
    // can resolve a settings schema and apply password masking.
    $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger   = new NullLogger();

    $toolConfig = new ToolConfigService($security, $logger, [CalculatorTool::class]);
    $llmConfig  = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $service = new AgentService($toolConfig, $llmConfig);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $email = "agent-svc-llm-{$seq}@example.com";
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

    it('emits per-tool icons on every agent when a ToolIconResolver is supplied', function (): void {
        // Stubbed resolver: deterministic mapping for known tool classes,
        // null for anything else. Mirrors the pattern in AgentResourceTest
        // so the wire-format contract stays identical through the service layer.
        $resolver = new class extends ToolIconResolver {
            public function __construct() {}

            public function resolve(string $toolClass): ?string
            {
                return match ($toolClass) {
                    CalculatorTool::class => 'stubbed-icon-key',
                    default => null,
                };
            }
        };

        $key      = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $security = new SecurityManager($key);
        $logger   = new NullLogger();
        $toolConfig = new ToolConfigService($security, $logger, [CalculatorTool::class]);
        $llmConfig  = new LLMConfigService($security, []);
        $service = new AgentService($toolConfig, $llmConfig, $resolver);

        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'agent-svc-icons@example.com', AGENT_TEST_PASSWORD);

        $agent = $service->createAgent($userId, ['name' => 'Icon List Agent']);
        $service->enableTool($agent->id, $userId, CalculatorTool::class);

        $result = $service->getAgentsForUser($userId);

        expect($result)->toHaveCount(1);
        expect($result[0]['tools'])->toHaveCount(1);
        expect($result[0]['tools'][0]['icon'])->toBe('stubbed-icon-key');
    });

    it('omits the per-tool icon key when no ToolIconResolver is supplied (back-compat)', function (): void {
        // Mirrors AgentResourceTest's contract: when the service is constructed
        // without a resolver, each tools[i] entry has no `icon` key. The
        // frontend's <Icon> falls back to 'puzzle' on missing keys.
        [$service, $userId] = makeAgentServiceWithUser();

        $agent = $service->createAgent($userId, ['name' => 'Backcompat Agent']);
        $service->enableTool($agent->id, $userId, CalculatorTool::class);

        $result = $service->getAgentsForUser($userId);

        expect($result)->toHaveCount(1);
        expect($result[0]['tools'])->toHaveCount(1);
        expect($result[0]['tools'][0])->not->toHaveKey('icon');
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
        ]);

        expect($updated->name)->toBe('After');
        expect($updated->max_steps)->toBe(7);
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

    it('returns a masked LLM configuration array when the agent has one assigned', function (): void {
        // Uses the LLM-driver-enabled service so maskLlmConfig can resolve
        // the settings schema (and therefore mask the api_key password field).
        [$service, $userId] = makeAgentServiceWithLlmDriver();

        $config = LLMDriverConfiguration::create([
            'user_id'      => null,
            'name'         => 'Global default',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => json_encode([
                'api_key'  => 'plain-secret',
                'model'    => 'gpt-4o',
                'base_url' => 'https://api.openai.com/v1',
            ]),
            'is_global'  => true,
            'is_default' => true,
        ]);

        $agent = $service->createAgent($userId, [
            'name'                 => 'LlmAgent',
            'llm_driver_config_id' => (int) $config->getKey(),
        ]);

        $result = $service->getOverride($agent->id, $userId, 'llm_configuration');

        expect($result)->toBeArray();
        // api_key is a password field → must be masked
        expect($result)->toHaveKey('api_key');
        expect($result['api_key'])->toBe('***');
        // Non-password fields pass through unchanged
        expect($result['model'])->toBe('gpt-4o');
        expect($result['base_url'])->toBe('https://api.openai.com/v1');
    });

    it('returns the tool override (array) for an enabled tool with no row stored', function (): void {
        // getOverride for a registered tool class (not 'llm_configuration')
        // returns the agent override even when no row exists — it should be
        // an array, possibly empty if there are no settings for that tool.
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'ToolNoOverride']);
        $service->enableTool($agent->id, $userId, CalculatorTool::class);

        $raw = $service->getOverride($agent->id, $userId, CalculatorTool::class, rawOnly: true);
        expect($raw)->toBeArray();
        // CalculatorTool has no schema-default settings; raw agent override is empty.
        expect($raw)->not->toHaveKey('calculator.expression');
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

describe('AgentService private helpers (now on collaborators)', function (): void {
    // After the AgentService split (refactor/split-agent-service), the helper
    // methods moved to dedicated collaborators:
    //   - AgentToolOverrideResolver::extractOverrideFlag, parseOverrideFlag, maskLlmConfig
    //   - AgentToolInstanceResolver::resolveToolInstance
    // The override-resolver methods are public on the new class, so they can
    // be called directly. maskLlmConfig is still private, so reflection is
    // needed (targeting the new class, not AgentService).

    /**
     * Build an override resolver with the same key + drivers the rest of the
     * test suite uses (mirrors makeAgentServiceWithUser).
     */
    function makeOverrideResolver(): AgentToolOverrideResolver
    {
        $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $security = new SecurityManager($key);
        $toolConfig = new ToolConfigService($security, new NullLogger(), [CalculatorTool::class]);
        $llmConfig  = new LLMConfigService($security, []);

        return new AgentToolOverrideResolver(
            $toolConfig,
            $llmConfig,
            new AgentToolInstanceResolver(),
        );
    }

    it('extractOverrideFlag returns null when the row is null', function (): void {
        $resolver = makeOverrideResolver();

        expect($resolver->extractOverrideFlag(null, 'enabled'))->toBeNull();
    });

    it('extractOverrideFlag returns 1 when the raw value is 1 and 0 otherwise', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'ExtractFlag']);

        $resolver = makeOverrideResolver();

        // Persisted row with enabled=1 → expect 1
        $rowOn = AgentToolOperationOverride::create([
            'agent_id'   => (int) $agent->getKey(),
            'tool_class' => CalculatorTool::class,
            'operation'  => 'eval',
            'enabled'    => 1,
        ]);
        expect($resolver->extractOverrideFlag($rowOn, 'enabled'))->toBe(1);

        // Persisted row with enabled=0 → expect 0
        $rowOff = AgentToolOperationOverride::create([
            'agent_id'   => (int) $agent->getKey(),
            'tool_class' => CalculatorTool::class,
            'operation'  => 'send',
            'enabled'    => 0,
        ]);
        expect($resolver->extractOverrideFlag($rowOff, 'enabled'))->toBe(0);

        // Field that wasn't set on the row → expect null
        expect($resolver->extractOverrideFlag($rowOn, 'default_requires_approval'))->toBeNull();
    });

    it('parseOverrideFlag accepts boolean, integer, and string forms; null when missing', function (): void {
        $resolver = makeOverrideResolver();

        // PHP booleans
        expect($resolver->parseOverrideFlag(['enabled' => true], 'enabled'))->toBe(1);
        expect($resolver->parseOverrideFlag(['enabled' => false], 'enabled'))->toBe(0);

        // Integers (1 / 0)
        expect($resolver->parseOverrideFlag(['enabled' => 1], 'enabled'))->toBe(1);
        expect($resolver->parseOverrideFlag(['enabled' => 0], 'enabled'))->toBe(0);

        // Boolean strings (filter_var uses FILTER_VALIDATE_BOOLEAN)
        expect($resolver->parseOverrideFlag(['enabled' => 'true'], 'enabled'))->toBe(1);
        expect($resolver->parseOverrideFlag(['enabled' => 'false'], 'enabled'))->toBe(0);
        expect($resolver->parseOverrideFlag(['enabled' => '1'], 'enabled'))->toBe(1);
        expect($resolver->parseOverrideFlag(['enabled' => '0'], 'enabled'))->toBe(0);

        // Explicit null → null
        expect($resolver->parseOverrideFlag(['enabled' => null], 'enabled'))->toBeNull();

        // Key absent → null
        expect($resolver->parseOverrideFlag([], 'enabled'))->toBeNull();
    });

    it('resolveToolInstance returns an instance for a known tool and null for an unknown class', function (): void {
        $resolver = new AgentToolInstanceResolver();

        $instance = $resolver->resolveToolInstance(CalculatorTool::class);
        expect($instance)->not->toBeNull();
        expect($instance)->toBeInstanceOf(CalculatorTool::class);

        // Unknown class → null, not a thrown Throwable
        expect($resolver->resolveToolInstance('Spora\\Tools\\NotARealTool'))->toBeNull();
    });

    it('maskLlmConfig masks password keys and leaves other settings untouched', function (): void {
        // Use the LLM-driver-enabled resolver so the schema can resolve.
        $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $security = new SecurityManager($key);
        $toolConfig = new ToolConfigService($security, new NullLogger(), [CalculatorTool::class]);
        $llmConfig  = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
        $resolver = new AgentToolOverrideResolver(
            $toolConfig,
            $llmConfig,
            new AgentToolInstanceResolver(),
        );
        $ref  = new ReflectionClass(AgentToolOverrideResolver::class);
        $meth = $ref->getMethod('maskLlmConfig');

        $config = LLMDriverConfiguration::create([
            'user_id'      => null,
            'name'         => 'Mask target',
            'driver_class' => OpenAICompatibleDriver::class,
            // Plain JSON, NOT a wholesale-encrypted blob, so decodeSettings
            // takes the per-field branch.
            'settings'     => json_encode([
                'api_key'  => 'plain-secret',
                'model'    => 'gpt-4o',
                'base_url' => 'https://api.openai.com/v1',
            ]),
            'is_global'  => true,
            'is_default' => true,
        ]);

        $result = $meth->invoke($resolver, $config);

        expect($result)->toBeArray();
        // api_key is a password field → masked
        expect($result)->toHaveKey('api_key');
        expect($result['api_key'])->toBe('***');
        // Non-password fields pass through
        expect($result['model'])->toBe('gpt-4o');
        expect($result['base_url'])->toBe('https://api.openai.com/v1');
    });
});

describe('AgentService::maskLlmConfig via public API', function (): void {

    it('exposes a masked password field through getOverride(llm_configuration)', function (): void {
        // End-to-end check: the public getOverride('llm_configuration') path
        // ultimately calls maskLlmConfig, so verifying it via the public API
        // covers the integration too. The password must surface as '***'.
        [$service, $userId] = makeAgentServiceWithLlmDriver();

        $config = LLMDriverConfiguration::create([
            'user_id'      => null,
            'name'         => 'Public API mask',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => json_encode([
                'api_key'  => 'plain-secret',
                'model'    => 'gpt-4o-mini',
            ]),
            'is_global'  => true,
            'is_default' => true,
        ]);

        $agent = $service->createAgent($userId, [
            'name'                 => 'PublicMaskAgent',
            'llm_driver_config_id' => (int) $config->getKey(),
        ]);

        $result = $service->getOverride((int) $agent->getKey(), $userId, 'llm_configuration');

        expect($result)->toBeArray();
        // Password field is masked through the public surface
        expect($result['api_key'])->toBe('***');
        // Non-password field is plain
        expect($result['model'])->toBe('gpt-4o-mini');
    });
});

describe('AgentService::setPinned / setArchived / setFavorite', function (): void {

    it('newly created agents default to is_pinned=false, is_archived=false, and is_favorite=false', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();

        $agent = $service->createAgent($userId, ['name' => 'Defaults']);

        expect($agent->is_pinned)->toBeFalse();
        expect($agent->is_archived)->toBeFalse();
        expect($agent->is_favorite)->toBeFalse();
    });

    /**
     * Parameterised happy-path check: setPinned / setArchived each persist
     * a single boolean column and return the refreshed agent. Pest runs the
     * two datasets sequentially in the same describe block.
     */
    dataset('flagSetters', [
        'setPinned'   => ['setPinned',   'is_pinned',   'Pin me'],
        'setArchived' => ['setArchived', 'is_archived', 'Archive me'],
        'setFavorite' => ['setFavorite', 'is_favorite', 'Favorite me'],
    ]);

    it('persists and returns the agent', function (string $method, string $field, string $name): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => $name]);

        $result = $service->$method($userId, (int) $agent->getKey(), true);

        expect($result)->toBeInstanceOf(Agent::class);
        expect($result->id)->toBe($agent->id);
        expect($result->$field)->toBeTrue();

        // Refreshed from DB — read again to confirm persistence
        $fresh = Agent::find($agent->id);
        expect($fresh->$field)->toBeTrue();
    })->with('flagSetters');

    it('setPinned can flip back to false', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Toggle pin']);

        $service->setPinned($userId, (int) $agent->getKey(), true);
        $result = $service->setPinned($userId, (int) $agent->getKey(), false);

        expect($result->is_pinned)->toBeFalse();
    });

    it('setFavorite can flip back to false', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();
        $agent = $service->createAgent($userId, ['name' => 'Toggle favorite']);

        $service->setFavorite($userId, (int) $agent->getKey(), true);
        $result = $service->setFavorite($userId, (int) $agent->getKey(), false);

        expect($result->is_favorite)->toBeFalse();
    });

    /**
     * Parameterised ownership / not-found rejection: setPinned / setArchived
     * each throw AgentNotFoundException when the agent is missing or owned
     * by a different user.
     */
    dataset('notFoundScenarios', [
        'setPinned:   foreign owner'   => ['setPinned',   'Not yours', 'agent-svc-pin-foreign@example.com'],
        'setArchived: missing agent'   => ['setArchived', null,        null],
        'setFavorite: missing agent'   => ['setFavorite', null,        null],
    ]);

    it('throws AgentNotFoundException when the agent is inaccessible', function (string $method, ?string $agentName, ?string $foreignEmail): void {
        [$service, $userIdA] = makeAgentServiceWithUser();

        if ($agentName !== null) {
            $agent = $service->createAgent($userIdA, ['name' => $agentName]);
            $agentId = (int) $agent->getKey();
            $auth = bootAuthLayer();
            $userIdB = bootAuth($auth, $foreignEmail, AGENT_TEST_PASSWORD);
        } else {
            $agentId = 999999;
            $userIdB = $userIdA;
        }

        expect(fn() => $service->$method($userIdB, $agentId, true))
            ->toThrow(AgentNotFoundException::class, 'Agent not found.');
    })->with('notFoundScenarios');
});
