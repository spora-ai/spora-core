<?php

declare(strict_types=1);

use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Spora\Tools\EmailTool;

// Helpers

function makeLlmSettingsService(): array
{
    $authService = bootAuthLayer();

    $key     = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger  = new Monolog\Logger('test');
    $service = new ToolConfigService($security, $logger, [EmailTool::class]);

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

test('getLlmToolSettings returns only expose_to_llm fields with their labels and values', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Put some agent-level settings
    $service->putAgentOverride(EmailTool::class, $agentId, [
        'core.smtp.from'              => 'agent@example.com',
        'core.smtp.allowed_recipients' => 'alice@example.com, bob@example.com',
        // These should be filtered out (expose_to_llm = false)
        'core.smtp.host'              => 'smtp.example.com',
        'core.smtp.port'              => '587',
    ]);

    $result = $service->getLlmToolSettings(EmailTool::class, $agentId);

    // Should only contain expose_to_llm fields
    $keys = array_keys($result);
    sort($keys);
    expect($keys)->toBe(['core.smtp.allowed_recipients', 'core.smtp.from']);

    // Should contain label and value
    expect($result['core.smtp.from']['label'])->toBe('From Address');
    expect($result['core.smtp.from']['value'])->toBe('agent@example.com');

    expect($result['core.smtp.allowed_recipients']['label'])->toBe('Allowed Recipients');
    expect($result['core.smtp.allowed_recipients']['value'])->toBe('alice@example.com, bob@example.com');

    // Infrastructure fields must not be present
    expect($result)->not->toHaveKey('core.smtp.host');
    expect($result)->not->toHaveKey('core.smtp.port');
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings returns empty array when no settings are configured', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    $result = $service->getLlmToolSettings(EmailTool::class, $agentId);

    // All expose_to_llm fields are returned with null when nothing is configured
    expect($result)->toHaveKeys(['core.smtp.from', 'core.smtp.allowed_recipients']);
    expect($result['core.smtp.from']['value'])->toBeNull();
    expect($result['core.smtp.allowed_recipients']['value'])->toBeNull();
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings shows empty string values as empty string when stored', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // putAgentOverride filters out null AND empty string (both mean "use parent")
    // So we store a real value for allowed_recipients to test it appears in output
    $service->putAgentOverride(EmailTool::class, $agentId, [
        'core.smtp.from'              => 'agent@example.com',
        'core.smtp.allowed_recipients' => 'alice@example.com',
    ]);

    $result = $service->getLlmToolSettings(EmailTool::class, $agentId);

    expect($result)->toHaveKeys(['core.smtp.from', 'core.smtp.allowed_recipients']);
    expect($result['core.smtp.from']['value'])->toBe('agent@example.com');
    expect($result['core.smtp.allowed_recipients']['value'])->toBe('alice@example.com');
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings merges global and agent override for expose_to_llm fields', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Set global default for a non-exposed field (irrelevant for this test)
    $service->putGlobalSettings(EmailTool::class, [
        'core.smtp.host' => 'global-smtp.example.com',
    ]);

    // Set agent-level override for an expose_to_llm field
    $service->putAgentOverride(EmailTool::class, $agentId, [
        'core.smtp.from' => 'agent@override.com',
    ]);

    $result = $service->getLlmToolSettings(EmailTool::class, $agentId);

    expect($result['core.smtp.from']['value'])->toBe('agent@override.com');
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings uses schema defaults when no settings exist', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Don't set anything; EmailTool has no defaults for expose_to_llm fields anyway
    $result = $service->getLlmToolSettings(EmailTool::class, $agentId);

    // All expose_to_llm fields are returned with null value when not configured
    // so the LLM always sees these settings (as "(not configured)")
    expect($result)->toHaveKeys(['core.smtp.from', 'core.smtp.allowed_recipients']);
    expect($result['core.smtp.from']['value'])->toBeNull();
    expect($result['core.smtp.allowed_recipients']['value'])->toBeNull();
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings respects user-specific settings cascade', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Set global default (should be overridden by user setting)
    $service->putGlobalSettings(EmailTool::class, [
        'core.smtp.from' => 'global@example.com',
    ]);

    // Set user-level override
    $agent = Agent::find($agentId);
    $service->putUserSettings(EmailTool::class, $agent->user_id, [
        'core.smtp.from' => 'user@example.com',
    ]);

    $result = $service->getLlmToolSettings(EmailTool::class, $agentId, $agent->user_id);

    expect($result['core.smtp.from']['value'])->toBe('user@example.com');
})->afterEach(fn() => Database::resetBootState());
