<?php

declare(strict_types=1);

namespace Tests\Unit;

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

/**
 * Tests for the 3-level tool settings cascade:
 * 1. Tool schema defaults
 * 2. Global tool configuration
 * 3. Agent-specific overrides
 *
 * These require a real DB connection to test agent_tool_overrides and
 * tool_configurations tables, plus the SecurityManager for encrypting
 * password fields.
 */
test('returns empty when no global or agent override exists', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('cascade-test@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);

    expect($effective)->toBeEmpty();
});

test('global config is returned when no agent override exists', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('global-cascade@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Set global settings
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-api-key',
        'max_results' => '50',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);

    expect($effective['api_key'])->toBe('global-api-key');
    expect($effective['max_results'])->toBe('50');
});

test('agent override overrides global config', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('agent-override@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Set global settings
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-api-key',
        'max_results' => '50',
    ]);

    // Set agent override for api_key only (scope: agent)
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-api-key',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);

    // api_key should be from agent override
    expect($effective['api_key'])->toBe('agent-api-key');
    // max_results should be from global (not overridden)
    expect($effective['max_results'])->toBe('50');
});

test('scope global keys in agent override are discarded', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('global-scope@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Set global settings
    $toolConfig->putGlobalSettings(TestTool::class, [
        'max_results' => '50',
    ]);

    // Attempt to set max_results in agent override (scope: global → should be discarded)
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'max_results' => '100', // scope: global → should be discarded
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);

    // max_results should still be the global value (100 was discarded, not applied)
    expect($effective['max_results'])->toBe('50');
});

test('getEffectiveSettingsWithSource annotates correctly', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('source-annotate@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Set global settings
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-api-key',
        'max_results' => '50',
    ]);

    // Set agent override for api_key only
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-api-key',
    ]);

    $annotated = $toolConfig->getEffectiveSettingsWithSource(TestTool::class, $agentId);

    // api_key is from agent override
    expect($annotated['api_key']['value'])->toBe('agent-api-key');
    expect($annotated['api_key']['source'])->toBe('agent');

    // max_results is from global
    expect($annotated['max_results']['value'])->toBe('50');
    expect($annotated['max_results']['source'])->toBe('global');
});

test('password masking via maskForApi', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('mask-test@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Set agent override with password field
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'secret-password',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);

    // Password should be decrypted (api_key is type: password but decrypted on read)
    expect($effective['api_key'])->toBe('secret-password');

    // maskForApi should mask password fields
    $masked = $toolConfig->maskForApi($effective, TestTool::class);

    expect($masked['api_key'])->toBe('***');
    expect($masked)->not()->toHaveKey('max_results'); // not set, should not appear
});

test('maskForApi handles empty and null password fields', function () {
    bootAuthLayer();

    $toolConfig = makeToolConfigService();

    // Test with null password
    $maskedNull = $toolConfig->maskForApi(['api_key' => null], TestTool::class);
    expect($maskedNull['api_key'])->toBeNull();

    // Test with empty password
    $maskedEmpty = $toolConfig->maskForApi(['api_key' => ''], TestTool::class);
    expect($maskedEmpty['api_key'])->toBe('');
});

test('deleteAgentOverride removes the override', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('delete-override@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Set global and agent override
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-key',
    ]);
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-key',
    ]);

    // Delete override
    $toolConfig->deleteAgentOverride(TestTool::class, $agentId);

    // Should fall back to global
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('global-key');
});

test('getEffectiveSettingsWithSource returns default source when only schema default exists', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('schema-default@example.com', 'Password1!');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // TestTool has no defaults set, so this tests the case where nothing is configured
    $annotated = $toolConfig->getEffectiveSettingsWithSource(TestTool::class, $agentId);

    // Since TestTool has no defaults, only the fields actually set should appear
    // Both api_key (scope: agent) and max_results (scope: global) have no defaults
    expect($annotated)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeToolConfigService(): ToolConfigService
{
    $key    = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger = new Logger('test');

    return new ToolConfigService($security, $logger, [TestTool::class]);
}

function createAgentForUser(int $userId): int
{
    $agent = new Agent();
    $agent->user_id = $userId;
    $agent->name = 'Test Agent for Cascade';
    $agent->save();

    return (int) $agent->id;
}
