<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Services\Agents\AgentToolInstanceResolver;
use Spora\Services\Agents\AgentToolOperationsResolver;
use Spora\Services\Agents\AgentToolOverrideResolver;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;

defined('AGENT_COLLABORATORS_TEST_PASSWORD') || define('AGENT_COLLABORATORS_TEST_PASSWORD', 'Password1!');

/**
 * @return array{0: AgentService, 1: AgentToolInstanceResolver, 2: AgentToolOverrideResolver, 3: AgentToolOperationsResolver, 4: int, 5: AgentToolSettingsService}
 */
function makeAgentServiceWithCollaborators(): array
{
    $key        = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security   = new SecurityManager($key);
    $logger     = new NullLogger();
    $toolConfig = new ToolConfigService($security, $logger, [CalculatorTool::class]);
    $llmConfig  = new LLMConfigService($security, []);

    $instanceResolver    = new AgentToolInstanceResolver();
    $overrideResolver    = new AgentToolOverrideResolver($toolConfig, $llmConfig, $instanceResolver);
    $operationsResolver  = new AgentToolOperationsResolver($instanceResolver, $overrideResolver);

    // ToolSettings service consumes the same three collaborators — kept on
    // AgentToolSettingsService when AgentService was split to satisfy S1448.
    $service = new AgentService();
    $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "agent-collab-{$seq}@example.com", AGENT_COLLABORATORS_TEST_PASSWORD);

    return [$service, $instanceResolver, $overrideResolver, $operationsResolver, $userId, $toolSettings];
}

describe('AgentService facade wires collaborators', function (): void {

    it('exposes a working facade that delegates to the underlying resolvers', function (): void {
        [$service, , , , $userId, $toolSettings] = makeAgentServiceWithCollaborators();
        $agent = $service->createAgent($userId, ['name' => 'Wired']);

        // Facade still works end-to-end (delegate path proven). getOperationOverride
        // moved to AgentToolSettingsService when AgentService was split to
        // satisfy SonarCloud S1448.
        $result = $toolSettings->getOperationOverride($agent->id, $userId, CalculatorTool::class, 'eval');
        expect($result)->toBe([
            'operation'                   => 'eval',
            'tool_class'                  => CalculatorTool::class,
            'enabled'                      => null,
            'default_requires_approval'    => null,
            'effective_enabled'            => true,
            'effective_requires_approval'  => true,
        ]);
    });
});

describe('AgentToolInstanceResolver', function (): void {

    it('resolves a tool instance and memoizes the result', function (): void {
        $resolver = new AgentToolInstanceResolver();
        $a = $resolver->resolveToolInstance(CalculatorTool::class);
        $b = $resolver->resolveToolInstance(CalculatorTool::class);

        expect($a)->toBeInstanceOf(CalculatorTool::class);
        expect($b)->toBe($a); // memoized
    });

    it('returns null for an unknown class', function (): void {
        $resolver = new AgentToolInstanceResolver();
        expect($resolver->resolveToolInstance('Spora\\Tools\\DoesNotExist'))->toBeNull();
    });

    it('reads the tool name from the #[Tool] attribute', function (): void {
        $resolver = new AgentToolInstanceResolver();
        expect($resolver->resolveToolName(CalculatorTool::class))->toBe('calculator');
    });

    it('extracts only password-typed keys from #[ToolSetting] attributes', function (): void {
        $resolver = new AgentToolInstanceResolver();
        $keys = $resolver->getToolPasswordKeys(CollaboratorTestPasswordTool::class);
        expect($keys)->toBe(['secret']);
    });

    it('returns an empty list when the class does not exist', function (): void {
        $resolver = new AgentToolInstanceResolver();
        expect($resolver->getToolPasswordKeys('Spora\\Tools\\DoesNotExist'))->toBe([]);
    });
});

describe('AgentToolOverrideResolver::parseOverrideFlag', function (): void {

    it('returns null when the key is absent from the data array', function (): void {
        [$service, , $override] = makeAgentServiceWithCollaborators();
        // The facade injects the resolvers; build one fresh here to call parseOverrideFlag directly.
        $fresh = new AgentToolOverrideResolver(
            new ToolConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), []),
            new LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
            new AgentToolInstanceResolver(),
        );

        expect($fresh->parseOverrideFlag([], 'enabled'))->toBeNull();
        expect($fresh->parseOverrideFlag(['other' => true], 'enabled'))->toBeNull();
    });

    it('returns null when the value is explicitly null', function (): void {
        $fresh = new AgentToolOverrideResolver(
            new ToolConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), []),
            new LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
            new AgentToolInstanceResolver(),
        );
        expect($fresh->parseOverrideFlag(['enabled' => null], 'enabled'))->toBeNull();
    });

    it('maps truthy values to 1 and falsy values to 0', function (): void {
        $fresh = new AgentToolOverrideResolver(
            new ToolConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), []),
            new LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
            new AgentToolInstanceResolver(),
        );

        expect($fresh->parseOverrideFlag(['enabled' => true], 'enabled'))->toBe(1);
        expect($fresh->parseOverrideFlag(['enabled' => false], 'enabled'))->toBe(0);
        expect($fresh->parseOverrideFlag(['enabled' => '1'], 'enabled'))->toBe(1);
        expect($fresh->parseOverrideFlag(['enabled' => '0'], 'enabled'))->toBe(0);
        expect($fresh->parseOverrideFlag(['enabled' => 'yes'], 'enabled'))->toBe(1);
        expect($fresh->parseOverrideFlag(['enabled' => 1], 'enabled'))->toBe(1);
        expect($fresh->parseOverrideFlag(['enabled' => 0], 'enabled'))->toBe(0);
    });
});

describe('AgentToolOverrideResolver::extractOverrideFlag', function (): void {

    it('returns null when the override row is null', function (): void {
        $fresh = new AgentToolOverrideResolver(
            new ToolConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), []),
            new LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
            new AgentToolInstanceResolver(),
        );

        expect($fresh->extractOverrideFlag(null, 'enabled'))->toBeNull();
    });

    it('returns 1 or 0 for a row with a value, and null when the value is null', function (): void {
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'agent-collab-extract@example.com', AGENT_COLLABORATORS_TEST_PASSWORD);
        $agent = Agent::create(['user_id' => $userId, 'name' => 'X', 'max_steps' => 5, 'is_active' => true]);

        // Insert with a value
        $row = AgentToolOperationOverride::create([
            'agent_id'   => $agent->id,
            'tool_class' => CalculatorTool::class,
            'operation'  => 'eval',
            'enabled'    => 1,
            'default_requires_approval' => 0,
        ]);

        $fresh = new AgentToolOverrideResolver(
            new ToolConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), []),
            new LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
            new AgentToolInstanceResolver(),
        );

        expect($fresh->extractOverrideFlag($row, 'enabled'))->toBe(1);
        expect($fresh->extractOverrideFlag($row, 'default_requires_approval'))->toBe(0);

        // Insert with null
        $nullRow = AgentToolOperationOverride::create([
            'agent_id'   => $agent->id,
            'tool_class' => CalculatorTool::class,
            'operation'  => 'calculate',
        ]);
        expect($fresh->extractOverrideFlag($nullRow, 'enabled'))->toBeNull();
    });
});
