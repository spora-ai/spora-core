<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;

defined('AGENT_TEST_PASSWORD') || define('AGENT_TEST_PASSWORD', 'Password1!');

/**
 * Tests for AgentToolSettingsService — extracted from AgentService when
 * the latter was split to satisfy SonarCloud S1448. Mirrors the shape of
 * AgentServiceTest::makeAgentServiceWithUser() so the existing assertions
 * still drive the same ToolConfigService / LLMConfigService wiring.
 *
 * @return array{0: AgentToolSettingsService, 1: int}
 */
function makeToolSettingsServiceWithUser(): array
{
    $key = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger   = new NullLogger();

    $toolConfig = new ToolConfigService($security, $logger, [CalculatorTool::class]);
    $llmConfig  = new LLMConfigService($security, []);

    $service = new AgentToolSettingsService($toolConfig, $llmConfig);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $email = "tool-settings-{$seq}@example.com";
    $userId = bootAuth($auth, $email, AGENT_TEST_PASSWORD);

    return [$service, $userId];
}

describe('AgentToolSettingsService::enableTool / disableTool', function (): void {

    it('enables a tool on an agent', function (): void {
        [$toolSettings, $userId] = makeToolSettingsServiceWithUser();
        // Use a real AgentService for the createAgent path so we don't
        // duplicate the lifecycle / flags code that moved out.
        $llmConfig = new LLMConfigService(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            [],
        );
        $agentService = new AgentService($llmConfig);
        $agent = $agentService->createAgent($userId, ['name' => 'Tooled']);

        $result = $toolSettings->enableTool($agent->id, $userId, CalculatorTool::class);
        expect($result['tool']['tool_class'])->toBe(CalculatorTool::class);
        expect($result['tool']['tool_name'])->toBe('calculator');
        expect($result)->not->toHaveKey('is_idempotent');
    });

    it('is idempotent when called twice', function (): void {
        [$toolSettings, $userId] = makeToolSettingsServiceWithUser();
        $llmConfig = new LLMConfigService(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            [],
        );
        $agentService = new AgentService($llmConfig);
        $agent = $agentService->createAgent($userId, ['name' => 'Tooled']);

        $first  = $toolSettings->enableTool($agent->id, $userId, CalculatorTool::class);
        $second = $toolSettings->enableTool($agent->id, $userId, CalculatorTool::class);

        expect($second)->toHaveKey('is_idempotent');
        expect($second['is_idempotent'])->toBeTrue();
        expect($first['tool']['tool_class'])->toBe($second['tool']['tool_class']);
    });

    it('returns error when agent does not exist', function (): void {
        [$toolSettings, $userId] = makeToolSettingsServiceWithUser();
        $result = $toolSettings->enableTool(9999, $userId, CalculatorTool::class);
        expect($result)->toBe(['error' => 'NOT_FOUND']);
    });

    it('disables a tool', function (): void {
        [$toolSettings, $userId] = makeToolSettingsServiceWithUser();
        $llmConfig = new LLMConfigService(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            [],
        );
        $agentService = new AgentService($llmConfig);
        $agent = $agentService->createAgent($userId, ['name' => 'Tooled']);
        $toolSettings->enableTool($agent->id, $userId, CalculatorTool::class);

        $toolSettings->disableTool($agent->id, $userId, CalculatorTool::class);

        $status = $toolSettings->getToolStatus($agent->id, $userId, CalculatorTool::class);
        expect($status['is_enabled'])->toBeFalse();
    });
});

describe('AgentToolSettingsService::getToolStatus / getAllToolsStatus', function (): void {

    it('returns null for a non-existent agent', function (): void {
        [$toolSettings, $userId] = makeToolSettingsServiceWithUser();
        expect($toolSettings->getToolStatus(9999, $userId, CalculatorTool::class))->toBeNull();
        expect($toolSettings->getAllToolsStatus(9999, $userId))->toBeNull();
    });

    it('reports is_enabled=false for a tool that has not been enabled', function (): void {
        [$toolSettings, $userId] = makeToolSettingsServiceWithUser();
        $llmConfig = new LLMConfigService(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            [],
        );
        $agentService = new AgentService($llmConfig);
        $agent = $agentService->createAgent($userId, ['name' => 'NoTools']);

        $status = $toolSettings->getToolStatus($agent->id, $userId, CalculatorTool::class);
        expect($status['is_enabled'])->toBeFalse();
        expect($status['tool_class'])->toBe(CalculatorTool::class);
    });

    it('lists all registered tools in getAllToolsStatus', function (): void {
        [$toolSettings, $userId] = makeToolSettingsServiceWithUser();
        $llmConfig = new LLMConfigService(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            [],
        );
        $agentService = new AgentService($llmConfig);
        $agent = $agentService->createAgent($userId, ['name' => 'HasTools']);

        $all = $toolSettings->getAllToolsStatus($agent->id, $userId);
        expect($all)->toBeArray();
        expect($all)->toHaveCount(1);
        expect($all[0]['tool_class'])->toBe(CalculatorTool::class);
    });
});
