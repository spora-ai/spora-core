<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;
use Spora\Services\LLMConfigService;

const PREF_TEST_USER_PASSWORD = 'Password1!';
const PREF_TEST_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

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
    $config->principal_id = $isGlobal ? null : createUserPrincipalPublic($userId);
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

// setUserPreferredConfig

test('setUserPreferredConfig creates preference row', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('pref1@example.com', PREF_TEST_USER_PASSWORD, 'Pref1');

    $config = createConfigForService($service, 'User Config', $userId);

    $result = $service->setUserPreferredConfig($userId, $config->id);

    expect($result)->toBeTrue();

    $pref = PrincipalPreference::where('principal_id', $userId)->first();
    expect($pref)->not()->toBeNull()
        ->and($pref->preferred_llm_config_id)->toBe($config->id);
});

test('setUserPreferredConfig updates existing preference', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('pref2@example.com', PREF_TEST_USER_PASSWORD, 'Pref2');

    $config1 = createConfigForService($service, 'First Config', $userId);
    $config2 = createConfigForService($service, 'Second Config', $userId);

    // Set first preference
    $service->setUserPreferredConfig($userId, $config1->id);
    $pref1 = PrincipalPreference::where('principal_id', $userId)->first();
    expect($pref1->preferred_llm_config_id)->toBe($config1->id);

    // Update to second preference
    $result = $service->setUserPreferredConfig($userId, $config2->id);
    expect($result)->toBeTrue();

    $pref2 = PrincipalPreference::where('principal_id', $userId)->first();
    expect($pref2->preferred_llm_config_id)->toBe($config2->id);

    // Should still be only one preference row
    expect(PrincipalPreference::where('principal_id', $userId)->count())->toBe(1);
});

test('setUserPreferredConfig rejects config belonging to another user', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userA = $authService->register('pref3a@example.com', PREF_TEST_USER_PASSWORD, 'Pref3a');
    $userB = $authService->register('pref3b@example.com', PREF_TEST_USER_PASSWORD, 'Pref3b');

    $configA = createConfigForService($service, 'User A Config', $userA);

    // User B tries to set User A's config as their preference
    $result = $service->setUserPreferredConfig($userB, $configA->id);

    expect($result)->toBeFalse();

    // User B should have no preference
    $pref = PrincipalPreference::where('principal_id', $userB)->first();
    expect($pref)->toBeNull();
});

test('setUserPreferredConfig allows global config', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('pref4@example.com', PREF_TEST_USER_PASSWORD, 'Pref4');

    // Create a global config
    $globalConfig = createConfigForService($service, 'Global Config', $userId, isGlobal: true);

    $result = $service->setUserPreferredConfig($userId, $globalConfig->id);

    expect($result)->toBeTrue();

    $pref = PrincipalPreference::where('principal_id', $userId)->first();
    expect($pref->preferred_llm_config_id)->toBe($globalConfig->id);
});

// getUserPreferredConfig

test('getUserPreferredConfig returns null when no preference', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('getpref1@example.com', PREF_TEST_USER_PASSWORD, 'Getpref1');

    $result = $service->getUserPreferredConfig($userId);

    expect($result)->toBeNull();
});

test('getUserPreferredConfig returns the preferred config', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('getpref2@example.com', PREF_TEST_USER_PASSWORD, 'Getpref2');

    $config = createConfigForService($service, 'My Preferred Config', $userId);
    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userId),
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
    $userA = $authService->register('getpref3a@example.com', PREF_TEST_USER_PASSWORD, 'Getpref3a');
    $userB = $authService->register('getpref3b@example.com', PREF_TEST_USER_PASSWORD, 'Getpref3b');

    $configA = createConfigForService($service, 'User A Config', $userA);
    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userA),
        'preferred_llm_config_id' => $configA->id,
    ]);

    // User B should not see User A's preference
    $result = $service->getUserPreferredConfig($userB);

    expect($result)->toBeNull();
});

// getEffectiveConfigForAgent uses preferred_llm_config_id (Tier 2)

test('getEffectiveConfigForAgent uses preferred_llm_config_id for tier-2 fallback', function (): void {
    [$service] = makePreferenceService();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'effective-t2-pref@example.com',
        'password' => password_hash(PREF_TEST_USER_PASSWORD, PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date(PREF_TEST_TIMESTAMP_FORMAT),
        'updated_at' => date(PREF_TEST_TIMESTAMP_FORMAT),
    ]);
    $principalId = createUserPrincipalPublic($userId);

    // Config that has is_default=true but is NOT the preferred one
    $defaultConfig = new LLMDriverConfiguration();
    $defaultConfig->principal_id = $principalId;
    $defaultConfig->name = 'Should Not Use';
    $defaultConfig->driver_class = AnthropicCompatibleDriver::class;
    $defaultConfig->settings = json_encode(['api_key' => 'test', 'model' => 'claude']);
    $defaultConfig->is_default = true;
    $defaultConfig->save();

    // Preferred config (different from is_default)
    $preferredConfig = new LLMDriverConfiguration();
    $preferredConfig->principal_id = $principalId;
    $preferredConfig->name = 'Should Use This';
    $preferredConfig->driver_class = OpenAICompatibleDriver::class;
    $preferredConfig->settings = json_encode(['api_key' => 'test', 'model' => 'gpt-4o']);
    $preferredConfig->save();

    PrincipalPreference::create([
        'principal_id' => $principalId,
        'preferred_llm_config_id' => $preferredConfig->id,
    ]);

    $agent = new Agent();
    $agent->id = 999;
    $agent->principal_id = $principalId;
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
        'password' => password_hash(PREF_TEST_USER_PASSWORD, PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date(PREF_TEST_TIMESTAMP_FORMAT),
        'updated_at' => date(PREF_TEST_TIMESTAMP_FORMAT),
    ]);
    $principalId = createUserPrincipalPublic($userId);

    // Agent-specific config (should be used - Tier 1)
    $agentConfig = new LLMDriverConfiguration();
    $agentConfig->principal_id = $principalId;
    $agentConfig->name = 'Agent Override';
    $agentConfig->driver_class = AnthropicCompatibleDriver::class;
    $agentConfig->settings = json_encode(['api_key' => 'test', 'model' => 'claude']);
    $agentConfig->save();

    // User preferred config (should NOT be used because agent has its own)
    $preferredConfig = new LLMDriverConfiguration();
    $preferredConfig->principal_id = $principalId;
    $preferredConfig->name = 'User Preferred';
    $preferredConfig->driver_class = OpenAICompatibleDriver::class;
    $preferredConfig->settings = json_encode(['api_key' => 'test', 'model' => 'gpt-4o']);
    $preferredConfig->save();

    PrincipalPreference::create([
        'principal_id' => $principalId,
        'preferred_llm_config_id' => $preferredConfig->id,
    ]);

    $agent = new Agent();
    $agent->id = 1000;
    $agent->principal_id = $principalId;
    $agent->llm_driver_config_id = $agentConfig->id;

    $result = $service->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($agentConfig->id)
        ->and($result->name)->toBe('Agent Override');
});

// unsetUserPreferredConfig

test('unsetUserPreferredConfig deletes the row', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('unsetpref1@example.com', PREF_TEST_USER_PASSWORD, 'Unsetpref1');

    $config = createConfigForService($service, 'To Remove', $userId);
    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'preferred_llm_config_id' => $config->id,
    ]);

    $service->unsetUserPreferredConfig($userId);

    $pref = PrincipalPreference::where('principal_id', $userId)->first();
    expect($pref)->toBeNull();
});

test('unsetUserPreferredConfig does nothing when no preference exists', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('unsetpref2@example.com', PREF_TEST_USER_PASSWORD, 'Unsetpref2');

    // Should not throw
    $service->unsetUserPreferredConfig($userId);

    $pref = PrincipalPreference::where('principal_id', $userId)->first();
    expect($pref)->toBeNull();
});

test('setUserPreferredConfig with the same config twice does not duplicate the preference row', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('setpref-twice@example.com', PREF_TEST_USER_PASSWORD, 'SetprefTwice');

    $config = createConfigForService($service, 'Double Set Config', $userId);

    $first = $service->setUserPreferredConfig($userId, (int) $config->getKey());
    $second = $service->setUserPreferredConfig($userId, (int) $config->getKey());

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        ->and(PrincipalPreference::where('principal_id', $userId)->count())->toBe(1);

    $pref = PrincipalPreference::where('principal_id', $userId)->first();
    expect($pref->preferred_llm_config_id)->toBe((int) $config->getKey());
});

test('unsetUserPreferredConfig is a no-op when called for a non-preferred user', function (): void {
    [$service] = makePreferenceService();
    $authService = bootAuthLayer();
    $userId = $authService->register('unsetpref-noop@example.com', PREF_TEST_USER_PASSWORD, 'UnsetprefNoop');

    // A different user has a preference — the no-op call must not affect it
    $otherUser = $authService->register('unsetpref-other@example.com', PREF_TEST_USER_PASSWORD, 'UnsetprefOther');
    $otherPrincipalId = createUserPrincipalPublic($otherUser);
    $otherConfig = createConfigForService($service, 'Other User Config', $otherUser);
    PrincipalPreference::create([
        'principal_id' => $otherPrincipalId,
        'preferred_llm_config_id' => (int) $otherConfig->getKey(),
    ]);

    // Should not throw and should not delete the other user's preference
    $service->unsetUserPreferredConfig($userId);

    $userPrincipalId = createUserPrincipalPublic($userId);
    expect(PrincipalPreference::where('principal_id', $userPrincipalId)->first())->toBeNull()
        ->and(PrincipalPreference::where('principal_id', $otherPrincipalId)->first())->not->toBeNull()
        ->and(PrincipalPreference::where('principal_id', $otherPrincipalId)->first()->preferred_llm_config_id)
        ->toBe((int) $otherConfig->getKey());
});
