<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\UserPreference;
use Spora\Services\LLMConfigService;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();
    Illuminate\Database\Capsule\Manager::connection()->beginTransaction();
});

afterEach(function (): void {
    if (Illuminate\Database\Capsule\Manager::connection()->transactionLevel() > 0) {
        Illuminate\Database\Capsule\Manager::connection()->rollBack();
    }
    Spora\Core\Database::resetBootState();
});

function makePreferenceService(): array
{
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);

    return [$service, $security];
}

function createConfigForService(LLMConfigService $service, string $name, int $userId, bool $isGlobal = false): LLMDriverConfiguration
{
    $config = new LLMDriverConfiguration();
    $config->user_id = $isGlobal ? null : $userId;
    $config->name = $name;
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings = json_encode($service->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test-' . uniqid(),
        'model' => 'gpt-4o',
    ]));
    $config->is_global = $isGlobal;
    $config->save();

    return $config;
}

// ---------------------------------------------------------------------------
// setUserPreferredConfig
// ---------------------------------------------------------------------------

test('setUserPreferredConfig creates preference row', function (): void {
    [$service, $security] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('pref1@example.com', 'Password1!', 'Pref1');

    $config = createConfigForService($service, 'User Config', $userId);

    $result = $service->setUserPreferredConfig($userId, $config->id);

    expect($result)->toBeTrue();

    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref)->not()->toBeNull()
        ->and($pref->preferred_llm_config_id)->toBe($config->id);
});

test('setUserPreferredConfig updates existing preference', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('pref2@example.com', 'Password1!', 'Pref2');

    $config1 = createConfigForService($service, 'First Config', $userId);
    $config2 = createConfigForService($service, 'Second Config', $userId);

    // Set first preference
    $service->setUserPreferredConfig($userId, $config1->id);
    $pref1 = UserPreference::where('user_id', $userId)->first();
    expect($pref1->preferred_llm_config_id)->toBe($config1->id);

    // Update to second preference
    $result = $service->setUserPreferredConfig($userId, $config2->id);
    expect($result)->toBeTrue();

    $pref2 = UserPreference::where('user_id', $userId)->first();
    expect($pref2->preferred_llm_config_id)->toBe($config2->id);

    // Should still be only one preference row
    expect(UserPreference::where('user_id', $userId)->count())->toBe(1);
});

test('setUserPreferredConfig rejects config belonging to another user', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userA = $authService->register('pref3a@example.com', 'Password1!', 'Pref3a');
    $userB = $authService->register('pref3b@example.com', 'Password1!', 'Pref3b');

    $configA = createConfigForService($service, 'User A Config', $userA);

    // User B tries to set User A's config as their preference
    $result = $service->setUserPreferredConfig($userB, $configA->id);

    expect($result)->toBeFalse();

    // User B should have no preference
    $pref = UserPreference::where('user_id', $userB)->first();
    expect($pref)->toBeNull();
});

test('setUserPreferredConfig allows global config', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('pref4@example.com', 'Password1!', 'Pref4');

    // Create a global config
    $globalConfig = createConfigForService($service, 'Global Config', $userId, isGlobal: true);

    $result = $service->setUserPreferredConfig($userId, $globalConfig->id);

    expect($result)->toBeTrue();

    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref->preferred_llm_config_id)->toBe($globalConfig->id);
});

// ---------------------------------------------------------------------------
// getUserPreferredConfig
// ---------------------------------------------------------------------------

test('getUserPreferredConfig returns null when no preference', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('getpref1@example.com', 'Password1!', 'Getpref1');

    $result = $service->getUserPreferredConfig($userId);

    expect($result)->toBeNull();
});

test('getUserPreferredConfig returns the preferred config', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('getpref2@example.com', 'Password1!', 'Getpref2');

    $config = createConfigForService($service, 'My Preferred Config', $userId);
    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    $result = $service->getUserPreferredConfig($userId);

    expect($result)->not()->toBeNull()
        ->and($result->id)->toBe($config->id)
        ->and($result->name)->toBe('My Preferred Config');
});

test('getUserPreferredConfig respects user isolation', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userA = $authService->register('getpref3a@example.com', 'Password1!', 'Getpref3a');
    $userB = $authService->register('getpref3b@example.com', 'Password1!', 'Getpref3b');

    $configA = createConfigForService($service, 'User A Config', $userA);
    UserPreference::create([
        'user_id' => $userA,
        'preferred_llm_config_id' => $configA->id,
    ]);

    // User B should not see User A's preference
    $result = $service->getUserPreferredConfig($userB);

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// getEffectiveConfigForAgent uses preferred_llm_config_id (Tier 2)
// ---------------------------------------------------------------------------

test('getEffectiveConfigForAgent uses preferred_llm_config_id for tier-2 fallback', function (): void {
    [$service] = makePreferenceService();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'effective-t2-pref@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Config that has is_default=true but is NOT the preferred one
    $defaultConfig = new LLMDriverConfiguration();
    $defaultConfig->user_id = $userId;
    $defaultConfig->name = 'Should Not Use';
    $defaultConfig->driver_class = AnthropicCompatibleDriver::class;
    $defaultConfig->settings = json_encode(['api_key' => 'test', 'model' => 'claude']);
    $defaultConfig->is_default = true;
    $defaultConfig->save();

    // Preferred config (different from is_default)
    $preferredConfig = new LLMDriverConfiguration();
    $preferredConfig->user_id = $userId;
    $preferredConfig->name = 'Should Use This';
    $preferredConfig->driver_class = OpenAICompatibleDriver::class;
    $preferredConfig->settings = json_encode(['api_key' => 'test', 'model' => 'gpt-4o']);
    $preferredConfig->save();

    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $preferredConfig->id,
    ]);

    $agent = new Agent();
    $agent->id = 999;
    $agent->user_id = $userId;
    $agent->llm_driver_config_id = null;

    $result = $service->getEffectiveConfigForAgent($agent);

    // Should use preferred config, NOT the is_default config
    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($preferredConfig->id)
        ->and($result->name)->toBe('Should Use This');
});

test('getEffectiveConfigForAgent prefers agent config over user preferred config', function (): void {
    [$service] = makePreferenceService();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'effective-t1-override@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Agent-specific config (should be used - Tier 1)
    $agentConfig = new LLMDriverConfiguration();
    $agentConfig->user_id = $userId;
    $agentConfig->name = 'Agent Override';
    $agentConfig->driver_class = AnthropicCompatibleDriver::class;
    $agentConfig->settings = json_encode(['api_key' => 'test', 'model' => 'claude']);
    $agentConfig->save();

    // User preferred config (should NOT be used because agent has its own)
    $preferredConfig = new LLMDriverConfiguration();
    $preferredConfig->user_id = $userId;
    $preferredConfig->name = 'User Preferred';
    $preferredConfig->driver_class = OpenAICompatibleDriver::class;
    $preferredConfig->settings = json_encode(['api_key' => 'test', 'model' => 'gpt-4o']);
    $preferredConfig->save();

    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $preferredConfig->id,
    ]);

    $agent = new Agent();
    $agent->id = 1000;
    $agent->user_id = $userId;
    $agent->llm_driver_config_id = $agentConfig->id;

    $result = $service->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($agentConfig->id)
        ->and($result->name)->toBe('Agent Override');
});

// ---------------------------------------------------------------------------
// unsetUserPreferredConfig
// ---------------------------------------------------------------------------

test('unsetUserPreferredConfig deletes the row', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('unsetpref1@example.com', 'Password1!', 'Unsetpref1');

    $config = createConfigForService($service, 'To Remove', $userId);
    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    $service->unsetUserPreferredConfig($userId);

    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref)->toBeNull();
});

test('unsetUserPreferredConfig does nothing when no preference exists', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('unsetpref2@example.com', 'Password1!', 'Unsetpref2');

    // Should not throw
    $service->unsetUserPreferredConfig($userId);

    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref)->toBeNull();
});
