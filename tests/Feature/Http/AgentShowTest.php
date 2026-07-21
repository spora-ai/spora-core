<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\AgentController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\AgentServiceInterface;
use Spora\Services\LLMConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Plan §12 B2b — AgentController::show() surfaces llm_supports_image_input.
 */
test('show() includes llm_supports_image_input=true for a vision-capable agent', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedShowLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedShowAgent(42, $userId);
    $controller = buildAgentController();
    $req = Request::create('/api/v1/agents/42', 'GET');
    $req->attributes->set('id', 42);
    $resp = $controller->show($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['agent']['llm_supports_image_input'])->toBeTrue();
});

test('show() includes llm_supports_image_input=false for a text-only agent', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedShowLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-3.5-turbo');
    seedShowAgent(42, $userId);
    $controller = buildAgentController();
    $req = Request::create('/api/v1/agents/42', 'GET');
    $req->attributes->set('id', 42);
    $resp = $controller->show($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['agent']['llm_supports_image_input'])->toBeFalse();
});

function buildAgentController(): AgentController
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    $factory = new DriverFactory(new NullLogger(), $llmService, 60);
    // Use a thin DB-backed agent service. The shared StubAgentService
    // returns a hand-constructed Agent model that ignores
    // llm_driver_config_id — show() needs the real DB row so the
    // DriverFactory can resolve the configured LLM.
    $agentService = new class implements AgentServiceInterface {
        public function getAgentsForUser(int $userId): array
        {
            return [];
        }
        public function createAgent(int $userId, array $data): Agent
        {
            throw new RuntimeException('not implemented in test');
        }
        public function getAgent(int $agentId, int $userId): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function updateAgent(int $agentId, int $userId, array $data): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function updateAgentByAgentId(int $agentId, array $data): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function getAgentByAgentId(int $agentId): ?Agent
        {
            return Agent::query()->find($agentId);
        }
        public function deleteAgent(int $agentId, int $userId): bool
        {
            return true;
        }
        public function setPinned(int $userId, int $agentId, bool $pinned): Agent
        {
            return Agent::query()->find($agentId) ?? throw new RuntimeException('agent not found');
        }
        public function setArchived(int $userId, int $agentId, bool $archived): Agent
        {
            return Agent::query()->find($agentId) ?? throw new RuntimeException('agent not found');
        }
        /** @phpstan-ignore return.unusedType */
        public function enableTool(int $agentId, int $userId, string $toolClass): array
        {
            return ['tool' => [], 'warning' => ''];
        }
        public function disableTool(int $agentId, int $userId, string $toolClass): void {}
        public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
        {
            return null;
        }
        public function getAllToolsStatus(int $agentId, int $userId): ?array
        {
            return null;
        }
        public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
        {
            return [];
        }
        public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
        {
            return [];
        }
        public function deleteOverride(int $agentId, int $userId, string $toolClass): void {}
        public function getToolsOperations(int $agentId, int $userId): ?array
        {
            return null;
        }
        /** @phpstan-ignore return.unusedType */
        public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
        {
            return [
                'operation' => $operation,
                'tool_class' => $toolClass,
                'enabled' => null,
                'default_requires_approval' => null,
                'effective_enabled' => false,
                'effective_requires_approval' => false,
            ];
        }
        public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
        {
            return [];
        }
    };
    return new AgentController(bootAuthLayer(), $agentService, $factory);
}

function seedShowLlmConfig(int $id, int $userId, string $driverClass, string $model): void
{
    LLMDriverConfiguration::query()->where('id', $id)->delete();
    LLMDriverConfiguration::query()->insert([
        'id' => $id,
        'user_id' => $userId,
        'name' => "cfg-{$id}",
        'driver_class' => $driverClass,
        'settings' => json_encode([
            'api_key' => '',
            'model' => $model,
            'base_url' => 'https://example.invalid/v1',
            'timeout' => '60',
        ]),
        'is_default' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function seedShowAgent(int $id, int $userId): void
{
    if (Agent::query()->find($id) !== null) {
        return;
    }
    Agent::query()->insert([
        'id' => $id,
        'user_id' => $userId,
        'name' => "agent-{$id}",
        'description' => '',
        'system_prompt' => '',
        'llm_driver_config_id' => 1,
        'max_steps' => 5,
        'is_active' => 1,
        'allow_followup' => 1,
        'retry_after_minutes' => 0,
        'max_retries' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}
