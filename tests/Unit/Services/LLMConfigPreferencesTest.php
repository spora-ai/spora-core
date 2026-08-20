<?php

declare(strict_types=1);

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;
use Spora\Services\LLMConfigPreferences;

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

function makePreferencesService(): array
{
    $authService = bootAuthLayer();
    $preferences = new LLMConfigPreferences();

    return [$preferences, $authService];
}

function makeGlobalDefaultConfig(string $name): LLMDriverConfiguration
{
    $config = new LLMDriverConfiguration();
    $config->principal_id = null;
    $config->is_global = true;
    $config->name = $name;
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings = encodeLlmSettingsForTest(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-' . uniqid(),
        'model' => 'gpt-4o',
    ]);
    $config->save();

    return $config;
}

test('setPrincipalPreferredConfig creates a preference row for a global config', function (): void {
    [$preferences, $authService] = makePreferencesService();
    $userId = $authService->register('pref-create@example.com', 'Password1!', 'Pref');
    $principalId = createUserPrincipalPublic($userId);
    $config = makeGlobalDefaultConfig('Global Cfg');

    $ok = $preferences->setPrincipalPreferredConfig($principalId, $config->id, $userId);

    expect($ok)->toBeTrue();

    $row = PrincipalPreference::where('principal_id', $principalId)->first();
    expect($row)->not->toBeNull()
        ->and($row->preferred_llm_config_id)->toBe($config->id);
});

test('setPrincipalPreferredConfig returns false when the target config does not exist', function (): void {
    [$preferences, $authService] = makePreferencesService();
    $userId = $authService->register('pref-missing@example.com', 'Password1!', 'Pref');
    $principalId = createUserPrincipalPublic($userId);

    expect($preferences->setPrincipalPreferredConfig($principalId, 999_999, $userId))->toBeFalse();
});

test('setPrincipalPreferredConfig rejects a config that belongs to another user', function (): void {
    [$preferences, $authService] = makePreferencesService();
    $userA = $authService->register('pref-a@example.com', 'Password1!', 'A');
    $userB = $authService->register('pref-b@example.com', 'Password1!', 'B');

    $configA = new LLMDriverConfiguration();
    $configA->principal_id = createUserPrincipalPublic($userA);
    $configA->name = 'A Only';
    $configA->driver_class = OpenAICompatibleDriver::class;
    $configA->settings = json_encode([]);
    $configA->save();

    $principalB = createUserPrincipalPublic($userB);
    expect($preferences->setPrincipalPreferredConfig($principalB, $configA->id, $userB))->toBeFalse();

    $row = PrincipalPreference::where('principal_id', $principalB)->first();
    expect($row)->toBeNull();
});

test('unsetPrincipalPreferredConfig deletes the row when one exists', function (): void {
    [$preferences, $authService] = makePreferencesService();
    $userId = $authService->register('pref-unset@example.com', 'Password1!', 'Unset');
    $principalId = createUserPrincipalPublic($userId);
    $config = makeGlobalDefaultConfig('To Unset');

    $preferences->setPrincipalPreferredConfig($principalId, $config->id, $userId);
    $preferences->unsetPrincipalPreferredConfig($principalId);

    $row = PrincipalPreference::where('principal_id', $principalId)->first();
    expect($row)->toBeNull();
});

test('unsetPrincipalPreferredConfig is a no-op when no preference exists', function (): void {
    [$preferences, $authService] = makePreferencesService();
    $userId = $authService->register('pref-unset-empty@example.com', 'Password1!', 'Empty');
    $principalId = createUserPrincipalPublic($userId);

    $preferences->unsetPrincipalPreferredConfig($principalId);

    $row = PrincipalPreference::where('principal_id', $principalId)->first();
    expect($row)->toBeNull();
});

test('getPrincipalPreferredConfig returns null when no preference has been set', function (): void {
    [$preferences, $authService] = makePreferencesService();
    $userId = $authService->register('pref-none@example.com', 'Password1!', 'None');
    $principalId = createUserPrincipalPublic($userId);

    expect($preferences->getPrincipalPreferredConfig($principalId))->toBeNull();
});

test('getEffectiveConfigForAgent falls back to global default when tiers 1 and 2 are empty', function (): void {
    $preferences = new LLMConfigPreferences();
    $global = makeGlobalDefaultConfig('Tier 3 Global');
    $global->is_default = true;
    $global->save();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'tier3@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $principalId = createUserPrincipalPublic($userId);

    $agent = new Agent();
    $agent->id = 1234;
    $agent->principal_id = $principalId;
    $agent->llm_driver_config_id = null;

    $result = $preferences->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($global->id)
        ->and($result->name)->toBe('Tier 3 Global');
});

test('getEffectiveConfigForAgent returns null at every tier when nothing is configured', function (): void {
    $preferences = new LLMConfigPreferences();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'all-null@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $principalId = createUserPrincipalPublic($userId);

    $agent = new Agent();
    $agent->id = 1235;
    $agent->principal_id = $principalId;
    $agent->llm_driver_config_id = null;

    expect($preferences->getEffectiveConfigForAgent($agent))->toBeNull();
});
