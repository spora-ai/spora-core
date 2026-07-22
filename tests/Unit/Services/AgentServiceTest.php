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
use Spora\Services\AgentToolSettingsService;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolIconResolver;
use Spora\Tools\CalculatorTool;

defined('AGENT_TEST_PASSWORD') || define('AGENT_TEST_PASSWORD', 'Password1!');

/**
 * @return array{0: AgentService, 1: int, 2: ToolConfigService, 3: LLMConfigService}
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

    $service = new AgentService($llmConfig);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $email = "agent-service-{$seq}@example.com";
    $userId = bootAuth($auth, $email, AGENT_TEST_PASSWORD);

    return [$service, $userId, $toolConfig, $llmConfig];
}

/**
 * @return array{0: AgentService, 1: int}
 */
function makeAgentServiceWithLlmDriver(): array
{
    // Like makeAgentServiceWithUser() but the LLMConfigService knows about
    // the OpenAI driver so getOverride('llm_configuration', ...) can resolve
    // a settings schema and apply password masking.
    $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger   = new NullLogger();

    $toolConfig = new ToolConfigService($security, $logger, [CalculatorTool::class]);
    $llmConfig  = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $service = new AgentService($llmConfig);

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
        $service = new AgentService($llmConfig, $resolver);

        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'agent-svc-icons@example.com', AGENT_TEST_PASSWORD);

        $agent = $service->createAgent($userId, ['name' => 'Icon List Agent']);
        // Tool enablement moved to AgentToolSettingsService when AgentService
        // was split to satisfy SonarCloud S1448.
        $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);
        $toolSettings->enableTool($agent->id, $userId, CalculatorTool::class);

        $result = $service->getAgentsForUser($userId);

        expect($result)->toHaveCount(1);
        expect($result[0]['tools'])->toHaveCount(1);
        expect($result[0]['tools'][0]['icon'])->toBe('stubbed-icon-key');
    });

    it('omits the per-tool icon key when no ToolIconResolver is supplied (back-compat)', function (): void {
        // Mirrors AgentResourceTest's contract: when the service is constructed
        // without a resolver, each tools[i] entry has no `icon` key. The
        // frontend's <Icon> falls back to 'puzzle' on missing keys.
        [$service, $userId, $toolConfig, $llmConfig] = makeAgentServiceWithUser();

        $agent = $service->createAgent($userId, ['name' => 'Backcompat Agent']);
        // Tool enablement moved to AgentToolSettingsService when AgentService
        // was split to satisfy SonarCloud S1448.
        $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);
        $toolSettings->enableTool($agent->id, $userId, CalculatorTool::class);

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


describe('AgentService private helpers (now on collaborators)', function (): void {
    // After the AgentService split (S1448), the helpers moved to dedicated
    // collaborators: extractOverrideFlag / parseOverrideFlag / maskLlmConfig
    // on AgentToolOverrideResolver, resolveToolInstance on
    // AgentToolInstanceResolver. maskLlmConfig is still private, so the
    // last test below uses reflection against the new class.

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

        // getOverride() moved to AgentToolSettingsService when AgentService
        // was split to satisfy SonarCloud S1448. The mask logic still
        // runs through AgentToolOverrideResolver internally.
        $llmConfig  = new LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), [OpenAICompatibleDriver::class]);
        $toolConfig = new ToolConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), [CalculatorTool::class]);
        $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);
        $result = $toolSettings->getOverride((int) $agent->getKey(), $userId, 'llm_configuration');

        expect($result)->toBeArray();
        // Password field is masked through the public surface
        expect($result['api_key'])->toBe('***');
        // Non-password field is plain
        expect($result['model'])->toBe('gpt-4o-mini');
    });
});

describe('AgentService::setPinned / setArchived', function (): void {

    it('newly created agents default to is_pinned=false and is_archived=false', function (): void {
        [$service, $userId] = makeAgentServiceWithUser();

        $agent = $service->createAgent($userId, ['name' => 'Defaults']);

        expect($agent->is_pinned)->toBeFalse();
        expect($agent->is_archived)->toBeFalse();
    });

    /**
     * Parameterised happy-path check: setPinned / setArchived each persist
     * a single boolean column and return the refreshed agent. Pest runs the
     * two datasets sequentially in the same describe block.
     */
    dataset('flagSetters', [
        'setPinned'   => ['setPinned',   'is_pinned',   'Pin me'],
        'setArchived' => ['setArchived', 'is_archived', 'Archive me'],
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

    /**
     * Parameterised ownership / not-found rejection: setPinned / setArchived
     * each throw AgentNotFoundException when the agent is missing or owned
     * by a different user.
     */
    dataset('notFoundScenarios', [
        'setPinned:   foreign owner'   => ['setPinned',   'Not yours', 'agent-svc-pin-foreign@example.com'],
        'setArchived: missing agent'   => ['setArchived', null,        null],
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
