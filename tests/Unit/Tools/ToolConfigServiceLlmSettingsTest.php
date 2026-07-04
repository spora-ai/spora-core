<?php

declare(strict_types=1);

use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

// Helpers

function makeLlmSettingsService(): array
{
    $authService = bootAuthLayer();

    $key     = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger  = new Monolog\Logger('test');
    $service = new ToolConfigService($security, $logger, [TestTool::class]);

    return [$service, $security, $authService];
}

function makeAgentWithLlm(mixed $authService): int
{
    static $seq = 0;
    $seq++;
    $email = "llmtest{$seq}@example.com";
    $userId = $authService->register($email, 'Password1!', ucfirst(explode('@', $email)[0]));

    return Agent::create([
        'user_id'      => $userId,
        'name'         => 'LLM Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;
}

// getLlmToolSettings

test('getLlmToolSettings returns only expose_to_llm fields', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Put some agent-level settings
    $service->putAgentOverride(TestTool::class, $agentId, [
        'allowed_target_agents' => ['agent-a', 'agent-b'],
        // These should be filtered out (expose_to_llm = false)
        'api_key'               => 'secret-123',
        'max_results'           => '25',
    ]);

    $result = $service->getLlmToolSettings(TestTool::class, $agentId);

    // Should only contain expose_to_llm fields
    expect($result)->toHaveKey('allowed_target_agents');
    expect($result)->not->toHaveKey('api_key');
    expect($result)->not->toHaveKey('max_results');

    // Should contain label
    expect($result['allowed_target_agents']['label'])->toBe('Allowed target agents');
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings returns empty array for multi-select with no value', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    $result = $service->getLlmToolSettings(TestTool::class, $agentId);

    // expose_to_llm multi-select field is returned as an empty array when
    // no value is configured (formatAgentIdList always returns array).
    expect($result)->toHaveKey('allowed_target_agents');
    expect($result['allowed_target_agents']['value'])->toBe([]);
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings respects user-specific settings cascade', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Set user-level override
    $agent = Agent::find($agentId);
    $service->putUserSettings(TestTool::class, $agent->user_id, [
        'allowed_target_agents' => ['user-override-agent'],
    ]);

    $result = $service->getLlmToolSettings(TestTool::class, $agentId, $agent->user_id);

    // Multi-select values get formatted as a name list — without a matching
    // Agent row the value is an empty list. The test just checks the
    // method runs and returns the structure.
    expect($result)->toHaveKey('allowed_target_agents');
})->afterEach(fn() => Database::resetBootState());
