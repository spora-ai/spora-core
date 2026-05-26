<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

/**
 * Tests for agent override merge behavior.
 *
 * Agent-level overrides now use merge semantics (like global/user levels):
 * - Partial updates preserve existing stored values
 * - Empty/null values break inheritance (field not stored)
 * - Scope filtering still applies (global-scoped fields rejected)
 */
test('agent override merges with existing settings', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('merge-test@example.com', 'Password1!', 'Merge Test');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Set first field
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'first-value',
    ]);

    // Set second field - should not erase first (custom_field is also scope: agent)
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'custom_field' => 'second-value',
    ]);

    // Both should be stored
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('first-value');
    expect($effective['custom_field'])->toBe('second-value');
});

test('partial update preserves existing fields', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('partial-test@example.com', 'Password1!', 'Partial Test');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Set both fields initially
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'old-key',
        'custom_field' => 'old-custom',
    ]);

    // Update only one field
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'new-key',
    ]);

    // Both should exist - custom_field preserved
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('new-key');
    expect($effective['custom_field'])->toBe('old-custom');
});

test('update overwrites same field', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('overwrite-test@example.com', 'Password1!', 'Overwrite Test');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Set initial value
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'original',
    ]);

    // Update to new value
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'updated',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('updated');
});

test('clearing a field removes it from stored override', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('clear-test@example.com', 'Password1!', 'Clear Test');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Set a value
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'stored-value',
    ]);

    // Clear it with null
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => null,
    ]);

    // Should not be in stored override
    $raw = $toolConfig->getRawAgentOverride(TestTool::class, $agentId);
    expect($raw)->not->toHaveKey('api_key');
});

test('sending null breaks inheritance to parent', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('null-inherit@example.com', 'Password1!', 'Null Inherit');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Set global value
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-value',
    ]);

    // Set agent override
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-value',
    ]);

    // Clear with null - should fall back to global
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => null,
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('global-value');
});

test('empty string is treated as null for inheritance', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('empty-string@example.com', 'Password1!', 'Empty String');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Set global value
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-value',
    ]);

    // Set agent override
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-value',
    ]);

    // Send empty string - should fall back to global
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => '',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('global-value');
});

test('agent override takes precedence over user settings', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('agent-v-user@example.com', 'Password1!', 'Agent V User');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    $toolConfig->putUserSettings(TestTool::class, $userId, [
        'api_key' => 'user-value',
    ]);

    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-value',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('agent-value');
});

test('agent override takes precedence over global settings', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('agent-v-global@example.com', 'Password1!', 'Agent V Global');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-value',
    ]);

    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-value',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('agent-value');
});

test('user settings take precedence over global settings', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('user-v-global@example.com', 'Password1!', 'User V Global');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-value',
    ]);

    $toolConfig->putUserSettings(TestTool::class, $userId, [
        'api_key' => 'user-value',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId, $userId);
    expect($effective['api_key'])->toBe('user-value');
});

test('agent override falls back to user then global then default', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('fallback-chain@example.com', 'Password1!', 'Fallback Chain');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Nothing at agent level - should use schema default for max_results
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['max_results'])->toBe('10'); // schema default
});

test('agent override falls back to user when global not set', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('fallback-user@example.com', 'Password1!', 'Fallback User');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    $toolConfig->putUserSettings(TestTool::class, $userId, [
        'api_key' => 'user-value',
    ]);

    // No global, no agent override - should use user
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId, $userId);
    expect($effective['api_key'])->toBe('user-value');
});

test('agent override falls back to global when user not set', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('fallback-global@example.com', 'Password1!', 'Fallback Global');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-value',
    ]);

    // No user settings, no agent override - should use global
    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('global-value');
});

test('scope global keys in agent override are discarded', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('scope-global@example.com', 'Password1!', 'Scope Global');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // Set global value
    $toolConfig->putGlobalSettings(TestTool::class, [
        'max_results' => '50',
    ]);

    // Try to set max_results in agent override (scope: global → should be discarded)
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'max_results' => '100',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['max_results'])->toBe('50');
});

test('scope agent keys can be overridden at agent level', function () {
    $authService = bootAuthLayer();
    $userId = $authService->register('scope-agent@example.com', 'Password1!', 'Scope Agent');

    $toolConfig = makeToolConfigServiceForMerge();
    $agentId = createAgentForMerge($userId);

    // api_key has scope: agent (default)
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-key',
    ]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('agent-key');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeToolConfigServiceForMerge(): ToolConfigService
{
    $key = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger = new Logger('test');

    return new ToolConfigService($security, $logger, [TestTool::class]);
}

function createAgentForMerge(int $userId): int
{
    $agent = new Agent();
    $agent->user_id = $userId;
    $agent->name = 'Test Agent for Merge';
    $agent->save();

    return (int) $agent->id;
}
