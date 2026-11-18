<?php

declare(strict_types=1);

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;
use Spora\Services\LLMConfigPreferences;

beforeEach(function (): void {
    TestDatabaseFactory::boot();
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

function makePrincipalConfig(int $principalId, string $name, bool $isDefault = false): LLMDriverConfiguration
{
    $config = new LLMDriverConfiguration();
    $config->principal_id = $principalId;
    $config->is_global = false;
    $config->name = $name;
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings = encodeLlmSettingsForTest(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-' . uniqid(),
        'model' => 'gpt-4o',
    ]);
    $config->is_default = $isDefault;
    $config->save();

    return $config;
}

function makeAgentForPrincipal(int $principalId, ?int $configId = null, int $id = 9000): Agent
{
    $agent = new Agent();
    $agent->id = $id;
    $agent->principal_id = $principalId;
    $agent->llm_driver_config_id = $configId;

    return $agent;
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

test('unsetPrincipalPreferredConfig clears the LLM preference and keeps the speech preference', function (): void {
    [$preferences, $authService] = makePreferencesService();
    $userId = $authService->register('pref-unset@example.com', 'Password1!', 'Unset');
    $principalId = createUserPrincipalPublic($userId);
    $config = makeGlobalDefaultConfig('To Unset');
    $speechConfigId = seedSpeechProviderConfig($principalId, 'To Unset Speech');

    $preferences->setPrincipalPreferredConfig($principalId, $config->id, $userId);
    PrincipalPreference::where('principal_id', $principalId)
        ->update(['preferred_speech_config_id' => $speechConfigId]);
    $preferences->unsetPrincipalPreferredConfig($principalId);

    $row = PrincipalPreference::where('principal_id', $principalId)->first();
    expect($row)->not->toBeNull()
        ->and($row->preferred_llm_config_id)->toBeNull()
        ->and((int) $row->preferred_speech_config_id)->toBe($speechConfigId);
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

test('resolveEffectiveConfigForAgent names the agent\'s own configuration as the source', function (): void {
    $preferences = new LLMConfigPreferences();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'source-agent@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $principalId = createUserPrincipalPublic($userId);

    $agentConfig = makePrincipalConfig($principalId, 'Agent Own Config');
    makePrincipalConfig($principalId, 'Principal Default', true);

    $resolved = $preferences->resolveEffectiveConfigForAgent(
        makeAgentForPrincipal($principalId, (int) $agentConfig->id, 9101),
    );

    expect($resolved['source'])->toBe('agent')
        ->and((int) $resolved['config']->id)->toBe((int) $agentConfig->id);
});

test('resolveEffectiveConfigForAgent prefers the group\'s preference over the group\'s default row', function (): void {
    $preferences = new LLMConfigPreferences();

    $ownerId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'source-group-pref@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $groupPrincipalId = $this->makeGroupPrincipal($ownerId, 'Group Preference Group');

    $groupDefault = makePrincipalConfig($groupPrincipalId, 'Group Default', true);
    $groupPreferred = makePrincipalConfig($groupPrincipalId, 'Group Preferred');
    PrincipalPreference::create([
        'principal_id' => $groupPrincipalId,
        'preferred_llm_config_id' => (int) $groupPreferred->id,
    ]);

    $resolved = $preferences->resolveEffectiveConfigForAgent(
        makeAgentForPrincipal($groupPrincipalId, null, 9102),
    );

    expect($resolved['source'])->toBe('group_preference')
        ->and((int) $resolved['config']->id)->toBe((int) $groupPreferred->id)
        ->and((int) $groupDefault->id)->not->toBe((int) $groupPreferred->id);
});

test('resolveEffectiveConfigForAgent resolves a group agent\'s group preference, not the personal one', function (): void {
    $preferences = new LLMConfigPreferences();

    $ownerId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'source-group-vs-user@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $userPrincipalId = createUserPrincipalPublic($ownerId);
    $groupPrincipalId = $this->makeGroupPrincipal($ownerId, 'Group Vs User Group');

    $personalPreferred = makePrincipalConfig($userPrincipalId, 'Personal Preferred');
    PrincipalPreference::create([
        'principal_id' => $userPrincipalId,
        'preferred_llm_config_id' => (int) $personalPreferred->id,
    ]);

    $groupPreferred = makePrincipalConfig($groupPrincipalId, 'Group Preferred');
    PrincipalPreference::create([
        'principal_id' => $groupPrincipalId,
        'preferred_llm_config_id' => (int) $groupPreferred->id,
    ]);

    $resolved = $preferences->resolveEffectiveConfigForAgent(
        makeAgentForPrincipal($groupPrincipalId, null, 9103),
    );

    expect($resolved['source'])->toBe('group_preference')
        ->and((int) $resolved['config']->id)->toBe((int) $groupPreferred->id);
});

test('resolveEffectiveConfigForAgent uses the group\'s own default row when the group has no preference', function (): void {
    $preferences = new LLMConfigPreferences();

    $ownerId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'source-group-default@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $groupPrincipalId = $this->makeGroupPrincipal($ownerId, 'Group Default Group');

    $groupDefault = makePrincipalConfig($groupPrincipalId, 'Group Default', true);

    $resolved = $preferences->resolveEffectiveConfigForAgent(
        makeAgentForPrincipal($groupPrincipalId, null, 9104),
    );

    expect($resolved['source'])->toBe('group_default')
        ->and((int) $resolved['config']->id)->toBe((int) $groupDefault->id);
});

test('resolveEffectiveConfigForAgent prefers the group\'s default row over the global default', function (): void {
    $preferences = new LLMConfigPreferences();

    $ownerId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'source-group-over-global@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $groupPrincipalId = $this->makeGroupPrincipal($ownerId, 'Group Over Global Group');

    $groupDefault = makePrincipalConfig($groupPrincipalId, 'Group Default', true);
    $global = makeGlobalDefaultConfig('Global Default');
    $global->is_default = true;
    $global->save();

    $resolved = $preferences->resolveEffectiveConfigForAgent(
        makeAgentForPrincipal($groupPrincipalId, null, 9105),
    );

    expect($resolved['source'])->toBe('group_default')
        ->and((int) $resolved['config']->id)->toBe((int) $groupDefault->id);
});

test('resolveEffectiveConfigForAgent falls back to the global default when the principal has neither', function (): void {
    $preferences = new LLMConfigPreferences();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'source-global@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $principalId = createUserPrincipalPublic($userId);

    $global = makeGlobalDefaultConfig('Global Default');
    $global->is_default = true;
    $global->save();

    $resolved = $preferences->resolveEffectiveConfigForAgent(
        makeAgentForPrincipal($principalId, null, 9106),
    );

    expect($resolved['source'])->toBe('global_default')
        ->and((int) $resolved['config']->id)->toBe((int) $global->id);
});

test('resolveEffectiveConfigForAgent reports no configuration when nothing is configured', function (): void {
    $preferences = new LLMConfigPreferences();

    $userId = Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'    => 'source-none@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $principalId = createUserPrincipalPublic($userId);

    $resolved = $preferences->resolveEffectiveConfigForAgent(
        makeAgentForPrincipal($principalId, null, 9107),
    );

    expect($resolved['source'])->toBe('none')
        ->and($resolved['config'])->toBeNull();
});
