<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

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
    $userId = $authService->register('cascade-test@example.com', 'Password1!', 'Cascadetest');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);

    // Schema defaults are seeded (max_results default = '10')
    expect($effective['max_results'])->toBe('10');
    // api_key has no schema default
    expect($effective)->not->toHaveKey('api_key');
});

test('global config is returned when no agent override exists', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('global-cascade@example.com', 'Password1!', 'Globalcascade');

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
    $userId = $authService->register('agent-override@example.com', 'Password1!', 'Agentoverride');

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
    $userId = $authService->register('global-scope@example.com', 'Password1!', 'Globalscope');

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
    $userId = $authService->register('source-annotate@example.com', 'Password1!', 'Sourceannotate');

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
    $userId = $authService->register('mask-test@example.com', 'Password1!', 'Masktest');

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
    expect($masked['max_results'])->toBe('10'); // schema default, not password, returned as-is
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
    $userId = $authService->register('delete-override@example.com', 'Password1!', 'Deleteoverride');

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
    $userId = $authService->register('schema-default@example.com', 'Password1!', 'Schemadefault');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // TestTool now has max_results default = '10' — it should appear with source 'default'
    $annotated = $toolConfig->getEffectiveSettingsWithSource(TestTool::class, $agentId);

    expect($annotated['max_results']['value'])->toBe('10');
    expect($annotated['max_results']['source'])->toBe('default');
});

test('effective settings falls back to global when user and agent layers are cleared', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('user-fallback@example.com', 'Password1!', 'Userfallback');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Set global, user, and agent override
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-key',
        'max_results' => '20',
    ]);
    $toolConfig->putUserSettings(TestTool::class, $userId, [
        'api_key' => 'user-key',
    ]);
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-key',
    ]);

    // Clear user settings
    $toolConfig->deleteUserSettings(TestTool::class, $userId);
    // Clear agent override
    $toolConfig->deleteAgentOverride(TestTool::class, $agentId);

    // Effective settings should now come from global
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('global-key');
    expect($effective['max_results'])->toBe('20');
});

test('schema defaults are used when all layers are empty', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('default-fallback@example.com', 'Password1!', 'Defaultfallback');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    // Nothing set anywhere — only schema defaults apply
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);

    // TestTool has max_results default = '10'
    expect($effective['max_results'])->toBe('10');
    // api_key has no schema default, so should be empty string
    expect(array_key_exists('api_key', $effective))->toBeFalse();
});

test('deleteAgentOverride is idempotent (no error if no override exists)', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('delete-no-override@example.com', 'Password1!', 'Deletenooverride');

    $toolConfig = makeToolConfigService();
    $agentId = createAgentForUser($userId);

    $toolConfig->deleteAgentOverride(TestTool::class, $agentId); // should not throw
    $toolConfig->deleteAgentOverride(TestTool::class, $agentId); // call twice = idempotent
    expect(true)->toBeTrue();
});

// Helpers

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
