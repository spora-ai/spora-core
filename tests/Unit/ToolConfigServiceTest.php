<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use
 * ToolConfigService with a freshly generated 32-byte master key.
 *
 * @return array{0: ToolConfigService, 1: SecurityManager}
 */
function makeToolConfigService(): array
{
    $authService = bootAuthLayer(); // boots DB + schema via Database::boot()

    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $service  = new ToolConfigService($security);

    return [$service, $security, $authService];
}

/**
 * Create a user+agent and return the agent ID.
 * Uses a unique email per call to avoid conflicts across tests.
 */
function makeAgent(mixed $authService, string $suffix = ''): int
{
    static $seq = 0;
    $seq++;
    $email  = "toolconfig{$seq}{$suffix}@example.com";
    $userId = $authService->register($email, 'Password1!');

    return Agent::create([
        'user_id'      => $userId,
        'name'         => 'Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;
}

// ---------------------------------------------------------------------------
// putGlobalSettings / getGlobalSettings
// ---------------------------------------------------------------------------

test('putGlobalSettings encrypts password field at rest and getGlobalSettings decrypts it', function (): void {
    [$service] = makeToolConfigService();
    $toolClass = TestTool::class;

    $service->putGlobalSettings($toolClass, [
        'api_key'     => 'my-secret-api-key',
        'max_results' => '25',
    ]);

    // Verify raw DB value is NOT the plaintext (it must be an encrypted blob)
    $rawJson = Capsule::table('tool_configurations')
        ->where('tool_class', $toolClass)
        ->value('settings');

    $raw = json_decode($rawJson, true);
    expect($raw['api_key'])->not()->toBe('my-secret-api-key');

    // Verify decryption round-trip returns the original value
    $settings = $service->getGlobalSettings($toolClass);
    expect($settings['api_key'])->toBe('my-secret-api-key');
});

test('non-password field is stored and returned as plain string without encryption', function (): void {
    [$service] = makeToolConfigService();
    $toolClass = TestTool::class;

    $service->putGlobalSettings($toolClass, [
        'max_results' => '50',
    ]);

    // Raw DB value must be the plaintext string
    $rawJson = Capsule::table('tool_configurations')
        ->where('tool_class', $toolClass)
        ->value('settings');

    $raw = json_decode($rawJson, true);
    expect($raw['max_results'])->toBe('50');

    // Service also returns it unchanged
    $settings = $service->getGlobalSettings($toolClass);
    expect($settings['max_results'])->toBe('50');
});

// ---------------------------------------------------------------------------
// maskForApi
// ---------------------------------------------------------------------------

test('maskForApi replaces non-empty password value with *** and leaves non-password fields unchanged', function (): void {
    [$service] = makeToolConfigService();

    $masked = $service->maskForApi([
        'api_key'     => 'super-secret',
        'max_results' => '10',
    ], TestTool::class);

    expect($masked['api_key'])->toBe('***');
    expect($masked['max_results'])->toBe('10');
});

test('maskForApi leaves null password field as-is', function (): void {
    [$service] = makeToolConfigService();

    $masked = $service->maskForApi(['api_key' => null, 'max_results' => '5'], TestTool::class);

    expect($masked['api_key'])->toBeNull();
    expect($masked['max_results'])->toBe('5');
});

test('maskForApi leaves empty-string password field as-is', function (): void {
    [$service] = makeToolConfigService();

    $masked = $service->maskForApi(['api_key' => '', 'max_results' => '5'], TestTool::class);

    expect($masked['api_key'])->toBe('');
    expect($masked['max_results'])->toBe('5');
});

// ---------------------------------------------------------------------------
// getEffectiveSettings
// ---------------------------------------------------------------------------

test('getEffectiveSettings without agent override returns global settings', function (): void {
    [$service] = makeToolConfigService();
    $toolClass = TestTool::class;

    $service->putGlobalSettings($toolClass, [
        'api_key'     => 'global-key',
        'max_results' => '20',
    ]);

    $effective = $service->getEffectiveSettings($toolClass, 1);

    expect($effective['api_key'])->toBe('global-key');
    expect($effective['max_results'])->toBe('20');
});

test('getEffectiveSettings with override: agent-scoped key from override wins over global', function (): void {
    [$service, , $authService] = makeToolConfigService();
    $toolClass = TestTool::class;
    $agentId   = makeAgent($authService);

    $service->putGlobalSettings($toolClass, [
        'api_key'     => 'global-key',
        'max_results' => '20',
    ]);

    $service->putAgentOverride($toolClass, $agentId, [
        'api_key' => 'agent-specific-key',
    ]);

    $effective = $service->getEffectiveSettings($toolClass, $agentId);

    expect($effective['api_key'])->toBe('agent-specific-key');
})->afterEach(fn() => Database::resetBootState());

test('getEffectiveSettings with override: global-scoped key is not overridden by agent override', function (): void {
    [$service, , $authService] = makeToolConfigService();
    $toolClass = TestTool::class;
    $agentId   = makeAgent($authService);

    $service->putGlobalSettings($toolClass, [
        'api_key'     => 'global-key',
        'max_results' => '20',
    ]);

    // max_results has scope: 'global' in TestTool — putAgentOverride must silently discard it
    $service->putAgentOverride($toolClass, $agentId, [
        'api_key'     => 'agent-key',
        'max_results' => '999',
    ]);

    $effective = $service->getEffectiveSettings($toolClass, $agentId);

    // The global value must be preserved
    expect($effective['max_results'])->toBe('20');
})->afterEach(fn() => Database::resetBootState());

// ---------------------------------------------------------------------------
// DecryptionFailedException resilience
// ---------------------------------------------------------------------------

test('getGlobalSettings returns null for a field whose ciphertext is corrupted in the DB', function (): void {
    [$service] = makeToolConfigService();
    $toolClass = TestTool::class;

    $service->putGlobalSettings($toolClass, [
        'api_key'     => 'valid-key',
        'max_results' => '10',
    ]);

    // Corrupt the api_key ciphertext by writing garbage bytes that decode as a
    // valid base64 blob but will fail the sodium MAC check.
    $rawJson = Capsule::table('tool_configurations')
        ->where('tool_class', $toolClass)
        ->value('settings');

    $raw             = json_decode($rawJson, true);
    $raw['api_key']  = base64_encode(str_repeat('X', 50)); // 50 bytes > 24-byte nonce min, wrong MAC

    Capsule::table('tool_configurations')
        ->where('tool_class', $toolClass)
        ->update(['settings' => json_encode($raw)]);

    $settings = $service->getGlobalSettings($toolClass);

    // DecryptionFailedException must be caught; the field returns null
    expect($settings['api_key'])->toBeNull();

    // Non-password fields must be unaffected
    expect($settings['max_results'])->toBe('10');
});
