<?php

declare(strict_types=1);

use Spora\Speech\InvalidAudioException;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;

/**
 * Registry tests for the new FK-driven five-tier cascade.
 *
 * Mirrors the cascade architecture on the LLM side: agents
 * speech_driver_config_id → principal_preferences.preferred_speech_config_id
 * (user) → same iterated over group principals → speech_provider_configurations
 * WHERE is_global AND is_default → first registered STT class as a last
 * resort. The settings / configure / label surface of the OpenAI
 * compatible provider now lives entirely on SpeechProviderConfiguration,
 * so the per-config-label binding and the configured() gate moved to
 * the persistence layer; the registry's job here is just the class
 * lookup + source attribution.
 */

final class StubConfiguredProvider implements SpeechToTextProviderInterface
{
    /** @var array<string, mixed> */
    public array $boundSettings = [];

    public function getName(): string
    {
        return 'stub-configured';
    }
    public function getDisplayName(): string
    {
        return 'Stub Configured';
    }
    public function isConfigured(): bool
    {
        return true;
    }
    public function bindLabel(string $label): void
    {
        // no-op for the stub — tests don't exercise label binding
    }
    public function bindSettings(array $settings): void
    {
        $this->boundSettings = $settings;
    }
    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        return new TranscriptionResult('stub');
    }
}

final class StubUnconfiguredProvider implements SpeechToTextProviderInterface
{
    public function getName(): string
    {
        return 'stub-unconfigured';
    }
    public function getDisplayName(): string
    {
        return 'Stub Unconfigured';
    }
    public function isConfigured(): bool
    {
        return false;
    }
    public function bindLabel(string $label): void
    {
        // no-op for the stub — tests don't exercise label binding
    }
    public function bindSettings(array $settings): void
    {
        // no-op — the unconfigured stub doesn't care about settings
    }
    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        throw new InvalidAudioException('never called');
    }
}

function buildRegistry(array $providers): SpeechToTextRegistry
{
    // Two-step wire-up breaks the `Registry <-> Persistence` cycle:
    // build the registry with a resolver that points at a sentinel,
    // build persistence against that registry, then patch the
    // sentinel. Mirrors how PHP-DI assembles the production graph.
    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $persistenceRef = new stdClass();
    $persistenceRef->persistence = null;
    $registry = new SpeechToTextRegistry(
        $providers,
        $principalService,
        static fn(): ?Spora\Services\SpeechProviderConfigPersistence => $persistenceRef->persistence,
    );
    $validator = new Spora\Services\SpeechProviderConfigValidator($registry);
    $security = new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $persistence = new Spora\Services\SpeechProviderConfigPersistence(
        $security,
        $validator,
        static fn(): SpeechToTextRegistry => $registry,
    );
    $persistenceRef->persistence = $persistence;

    return $registry;
}

test('empty registry — all returns empty, configured returns null, describe returns empty', function (): void {
    $registry = buildRegistry([]);

    expect($registry->all())->toBe([])
        ->and($registry->configuredProvider())->toBeNull()
        ->and($registry->describe())->toBe([null, null, null]);
});

test('configuredProvider() returns null when no tier matches — no auto-pick of registered class', function (): void {
    // Tier 5 fallback is gone; configuredProvider() short-circuits
    // to null and the controller surfaces SPEECH_PROVIDER_UNAVAILABLE.
    $registry = buildRegistry([
        new StubConfiguredProvider(),
        new StubUnconfiguredProvider(),
    ]);

    expect($registry->configuredProvider(7))->toBeNull();
});

test('describe() returns [null, null] when no config exists — no "fallback" class leak', function (): void {
    // Tier 5 returns null so the cascade surfaces "No speech provider
    // configured" instead of the misleading "Using X (fallback)".
    $registry = buildRegistry([
        new StubConfiguredProvider(),
        new StubUnconfiguredProvider(),
    ]);

    [$class, $source] = $registry->describe(99);
    expect($class)->toBeNull()
        ->and($source)->toBeNull();
});

test('all() returns the providers in their constructor order', function (): void {
    $a = new StubConfiguredProvider();
    $b = new StubUnconfiguredProvider();

    $registry = buildRegistry([$a, $b]);

    expect($registry->all())->toBe([$a, $b]);
});

test('Tier 1: agent.speech_driver_config_id wins over every other tier', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    // Build an agent with speech_driver_config_id pointing at OAI.
    Illuminate\Database\Capsule\Manager::table('agents')->insert([
        'id' => 42,
        'principal_id' => createUserPrincipalPublic(7),
        'name' => 'Tier1 Agent',
        'speech_driver_config_id' => null, // set after the config row exists
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $configId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => null,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'OAI Agent',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('agents')->where('id', 42)->update(['speech_driver_config_id' => $configId]);

    // Also set a user preference to a DIFFERENT class to make sure
    // tier 1 wins.
    $userPrincipalId = createUserPrincipalPublic(7);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $userPrincipalId,
        'preferred_speech_config_id' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insert([
        'principal_id' => $userPrincipalId,
        'provider_class' => StubConfiguredProvider::class,
        'display_name' => 'Personal',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $stubConfigId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')
        ->where('principal_id', $userPrincipalId)
        ->value('id');
    Illuminate\Database\Capsule\Manager::table('principal_preferences')
        ->where('principal_id', $userPrincipalId)
        ->update(['preferred_speech_config_id' => $stubConfigId]);

    $registry = buildRegistry([$oai, new StubConfiguredProvider()]);

    [$class, $source] = $registry->describe(7, 42);
    expect($class)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($source)->toBe('agent');
});

test('Tier 2: user-principal preference wins when no agent override is set', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    $userId = 11;
    $userPrincipalId = createUserPrincipalPublic($userId);

    $configId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $userPrincipalId,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'Mine',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $userPrincipalId,
        'preferred_speech_config_id' => $configId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $registry = buildRegistry([$oai, new StubConfiguredProvider()]);

    [$class, $source] = $registry->describe($userId, null);
    expect($class)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($source)->toBe('user_preference');
});

test('Tier 3: group preference (joined_at ASC) is consulted when no user preference is set', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    $userId = 13;
    $userPrincipalId = createUserPrincipalPublic($userId);
    $ownerUserId = 14;
    createUserPrincipalPublic($ownerUserId);

    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $groupService = new Spora\Services\GroupService($principalService);
    $groupA = $groupService->createGroup($ownerUserId, 'RegGrpA');
    $groupB = $groupService->createGroup($ownerUserId, 'RegGrpB');
    $groupService->addMember((int) $groupA->id, $userId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerUserId);
    $groupService->addMember((int) $groupB->id, $userId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerUserId);

    $groupAPrincipalId = (int) Illuminate\Database\Capsule\Manager::table('principals')
        ->where('type', Spora\Models\Principal::TYPE_GROUP)
        ->where('group_id', $groupA->id)
        ->value('id');
    $groupBPrincipalId = (int) Illuminate\Database\Capsule\Manager::table('principals')
        ->where('type', Spora\Models\Principal::TYPE_GROUP)
        ->where('group_id', $groupB->id)
        ->value('id');

    // Group B has the OAI preference, group A has nothing. The
    // joined_at order is group A first, but A has no preference so
    // the cascade should fall through to B's preference.
    $groupBConfigId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $groupBPrincipalId,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'Group B',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $groupBPrincipalId,
        'preferred_speech_config_id' => $groupBConfigId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $registry = buildRegistry([$oai]);

    [$class, $source] = $registry->describe($userId, null);
    expect($class)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($source)->toBe('group_preference');
});

test('Tier 4: global default fires when no agent / user / group preference is set', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insert([
        'principal_id' => null,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'Global Default',
        'settings' => '{}',
        'is_default' => true,
        'is_global' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $registry = buildRegistry([$oai]);

    [$class, $source] = $registry->describe(99, null);
    expect($class)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($source)->toBe('global_default');
});

test('Tier 5: returns [null, null, null] when nothing else resolves — no silent fallback class leak', function (): void {
    // Tier 5 returns null so the SPA renders "No speech provider
    // configured" and the transcribe endpoint throws
    // SPEECH_PROVIDER_UNAVAILABLE (503) instead of letting an
    // empty-key OpenAI driver fire upstream and 401.
    $first = new StubConfiguredProvider();
    $second = new StubUnconfiguredProvider();

    $registry = buildRegistry([$first, $second]);

    [$class, $source] = $registry->describe(99, null);
    expect($class)->toBeNull()
        ->and($source)->toBeNull();
});

test('Unregistered class on tier 2 falls through (plugin uninstalled mid-life)', function (): void {
    $userId = 19;
    $userPrincipalId = createUserPrincipalPublic($userId);

    // Set up a config row for a class the registry doesn't know
    // about (simulating a plugin that's been uninstalled).
    $configId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $userPrincipalId,
        'provider_class' => 'SomeOld\\Class\\NotRegistered',
        'display_name' => 'Ghost',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $userPrincipalId,
        'preferred_speech_config_id' => $configId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $registry = buildRegistry([new StubConfiguredProvider()]);

    [$class, $source] = $registry->describe($userId, null);
    expect($class)->toBeNull()
        ->and($source)->toBeNull();
});

test('Per-agent cascade: a group-owned agent picks the GROUP preference, not the caller\'s user preference', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    $callerId = 30;
    $callerPrincipalId = createUserPrincipalPublic($callerId);

    // Caller's user-principal preference points at OAI; group's principal
    // preference points at the stub. The wrong behaviour (old cascade) would
    // return the caller's user preference; the correct behaviour for a
    // group-owned agent is the group's preference.
    $userConfigId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $callerPrincipalId,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'My Personal',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $callerPrincipalId,
        'preferred_speech_config_id' => $userConfigId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $ownerId = 31;
    createUserPrincipalPublic($ownerId);
    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $groupService = new Spora\Services\GroupService($principalService);
    $group = $groupService->createGroup($ownerId, 'RegCascadeGrp');
    $groupService->addMember((int) $group->id, $callerId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
    $groupPrincipalId = (int) Illuminate\Database\Capsule\Manager::table('principals')
        ->where('type', Spora\Models\Principal::TYPE_GROUP)
        ->where('group_id', $group->id)
        ->value('id');

    $groupConfigId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $groupPrincipalId,
        'provider_class' => StubConfiguredProvider::class,
        'display_name' => 'Group Default',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $groupPrincipalId,
        'preferred_speech_config_id' => $groupConfigId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $groupAgentId = (int) Spora\Models\Agent::create([
        'principal_id' => $groupPrincipalId,
        'name' => 'GroupAgent',
        'max_steps' => 5,
        'is_active' => true,
    ])->id;

    $registry = buildRegistry([$oai, new StubConfiguredProvider()]);

    [$class, $source] = $registry->describe($callerId, $groupAgentId);
    expect($class)->toBe(StubConfiguredProvider::class)
        ->and($source)->toBe('group_preference');
});

test('Per-agent cascade: a user-owned agent still picks the user-principal preference', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    $userId = 32;
    $userPrincipalId = createUserPrincipalPublic($userId);

    $configId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $userPrincipalId,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'My Own',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $userPrincipalId,
        'preferred_speech_config_id' => $configId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $userAgentId = (int) Spora\Models\Agent::create([
        'principal_id' => $userPrincipalId,
        'name' => 'UserAgent',
        'max_steps' => 5,
        'is_active' => true,
    ])->id;

    $registry = buildRegistry([$oai, new StubConfiguredProvider()]);

    [$class, $source] = $registry->describe($userId, $userAgentId);
    expect($class)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($source)->toBe('user_preference');
});

test('Per-agent cascade: a group-owned agent with no group preference falls through to global default', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insert([
        'principal_id' => null,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'GlobalDefault',
        'settings' => '{}',
        'is_default' => true,
        'is_global' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $callerId = 33;
    $ownerId = 34;
    createUserPrincipalPublic($callerId);
    createUserPrincipalPublic($ownerId);
    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $groupService = new Spora\Services\GroupService($principalService);
    $group = $groupService->createGroup($ownerId, 'RegCascadeGrp2');
    $groupService->addMember((int) $group->id, $callerId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
    $groupPrincipalId = (int) Illuminate\Database\Capsule\Manager::table('principals')
        ->where('type', Spora\Models\Principal::TYPE_GROUP)
        ->where('group_id', $group->id)
        ->value('id');
    $groupAgentId = (int) Spora\Models\Agent::create([
        'principal_id' => $groupPrincipalId,
        'name' => 'GroupAgent2',
        'max_steps' => 5,
        'is_active' => true,
    ])->id;

    $registry = buildRegistry([$oai]);

    [$class, $source, $configId] = $registry->describe($callerId, $groupAgentId);
    expect($class)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($source)->toBe('global_default')
        ->and($configId)->toBeInt();
});

test('Per-agent cascade: malformed agent id or missing principal falls back to caller-scoped path', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(Spora\Services\ToolConfigService::class));

    $userId = 35;
    $userPrincipalId = createUserPrincipalPublic($userId);
    $configId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $userPrincipalId,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'Mine',
        'settings' => '{}',
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $userPrincipalId,
        'preferred_speech_config_id' => $configId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $registry = buildRegistry([$oai, new StubConfiguredProvider()]);

    // Both `agentId <= 0` and "agent doesn't exist" should degrade to the
    // legacy caller-scoped path so the composer recording button keeps
    // working even when the agent page hasn't loaded yet.
    [$classA, $sourceA] = $registry->describe($userId, 0);
    [$classB, $sourceB] = $registry->describe($userId, 999_999_999);
    expect($classA)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($sourceA)->toBe('user_preference')
        ->and($classB)->toBe(OpenAiCompatibleTranscriber::class)
        ->and($sourceB)->toBe('user_preference');
});

test('configuredProvider() pushes decoded v2 settings into bindSettings() on tier 2', function (): void {
    $userId = 21;
    $userPrincipalId = createUserPrincipalPublic($userId);

    // Insert a v2 config whose settings column is the encrypted blob the
    // persistence layer would produce for `{"api_key":"sk-from-v2"}`.
    // We use the real SecurityManager + Validator so decodeSettings
    // round-trips like production. The persistence prunes settings
    // against the provider class's #[ToolSetting] schema, so we use
    // OpenAiCompatibleTranscriber (which declares `api_key`).
    $security = new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $openAi = new OpenAiCompatibleTranscriber(
        new Symfony\Component\HttpClient\MockHttpClient(),
        Mockery::mock(Spora\Services\ToolConfigService::class),
    );
    $registry = buildRegistry([$openAi]);
    $validator = new Spora\Services\SpeechProviderConfigValidator($registry);
    $persistence = new Spora\Services\SpeechProviderConfigPersistence(
        $security,
        $validator,
        static fn(): SpeechToTextRegistry => $registry,
    );
    $encrypted = $persistence->encodeSettingsString(
        OpenAiCompatibleTranscriber::class,
        ['api_key' => 'sk-from-v2'],
    );

    $configId = (int) Illuminate\Database\Capsule\Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id' => $userPrincipalId,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name' => 'V2 user-scope',
        'settings' => $encrypted,
        'is_default' => false,
        'is_global' => false,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
        'principal_id' => $userPrincipalId,
        'preferred_speech_config_id' => $configId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $configured = $registry->configuredProvider($userId, null);
    expect($configured)->toBeInstanceOf(OpenAiCompatibleTranscriber::class);

    // The decoded v2 settings must reach the provider — proves the
    // registry now hands the cascade-resolved config to providers
    // instead of forcing them to read from `tool_user_settings`.
    /** @var Spora\Speech\OpenAiCompatibleTranscriber $configured */
    expect($configured->boundSettings())->toBe(['api_key' => 'sk-from-v2']);
});

test('configuredProvider() returns null when no FK config exists', function (): void {
    // Tier 5 fallback is gone — cascade returns `[null, null, null]`
    // and configuredProvider() short-circuits to null.
    $stub = new StubConfiguredProvider();
    $registry = buildRegistry([$stub]);

    expect($registry->configuredProvider(99, null))->toBeNull();
});

test('DI bindings resolve Registry + Persistence + Validator without a cycle', function (): void {
    // Regression guard for the cycle that landed with the speech-input
    // contract (#238):
    //   Controller -> Service -> Validator -> Registry -> Persistence -> Validator
    // Broken by injecting the cross-class collaborators as lazy
    // Closures — neither factory resolves the other class during its
    // own construction, so PHP-DI's container build stays acyclic.
    // Any future eager `->get()` of the cross-class collaborator in
    // either factory re-introduces the cycle and fails this test.
    $provider = new class implements SpeechToTextProviderInterface {
        public function getName(): string
        {
            return 'stub';
        }
        public function getDisplayName(): string
        {
            return 'Stub';
        }
        public function isConfigured(): bool
        {
            return true;
        }
        public function transcribe(
            string $bytes,
            string $mimeType,
            ?string $languageHint = null,
            ?int $agentId = null,
            ?int $userId = null,
        ): TranscriptionResult {
            return new TranscriptionResult(text: 'stub', language: null, durationMs: null, metadata: []);
        }
        public function bindSettings(array $settings): void {}
        public function bindLabel(string $label): void {}
    };

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        'config' => ['app_env' => 'testing', 'key_path' => null],
        Spora\Core\SecurityManagerInterface::class => static fn(): Spora\Core\SecurityManager
            => new Spora\Core\SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        Spora\Plugins\PluginLoader::class => static fn(): Spora\Plugins\PluginLoader
            => new Spora\Plugins\PluginLoader([]),
        Spora\Services\PrincipalService::class => static fn(): Spora\Services\PrincipalService
            => new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()),
        'speech_to_text_provider_classes' => [],
        'speech_to_text_provider_classes_merged' => static fn(): array => [$provider],
        SpeechToTextRegistry::class => static function (Psr\Container\ContainerInterface $c): SpeechToTextRegistry {
            $providers = [];
            foreach ($c->get('speech_to_text_provider_classes_merged') as $instance) {
                $providers[] = $instance;
            }
            return new SpeechToTextRegistry(
                $providers,
                $c->has(Spora\Services\PrincipalService::class) ? $c->get(Spora\Services\PrincipalService::class) : new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()),
                static fn(): ?Spora\Services\SpeechProviderConfigPersistence
                    => $c->has(Spora\Services\SpeechProviderConfigPersistence::class)
                        ? $c->get(Spora\Services\SpeechProviderConfigPersistence::class)
                        : null,
            );
        },
    ]);
    $builder->addDefinitions(Spora\Core\SpeechProviderConfigContainerBindings::all());

    $container = $builder->build();

    expect($container->get(SpeechToTextRegistry::class))->toBeInstanceOf(SpeechToTextRegistry::class);
    expect($container->get(Spora\Services\SpeechProviderConfigPersistence::class))->toBeInstanceOf(Spora\Services\SpeechProviderConfigPersistence::class);
    expect($container->get(Spora\Services\SpeechProviderConfigValidator::class))->toBeInstanceOf(Spora\Services\SpeechProviderConfigValidator::class);

    // Resolve Registry BEFORE Persistence (the controller autowiring
    // path) and confirm the lazy Closure still wires persistence into
    // the registry once Persistence is built — guards the
    // "Registry built first, Persistence built later" runtime path.
    $registry = $container->get(SpeechToTextRegistry::class);
    $container->get(Spora\Services\SpeechProviderConfigPersistence::class);
    $reflection = new ReflectionClass($registry);
    $resolver = $reflection->getProperty('persistenceResolver')->getValue($registry);
    expect($resolver)->toBeInstanceOf(Closure::class);
    $persistence = ($resolver)();
    expect($persistence)->toBeInstanceOf(Spora\Services\SpeechProviderConfigPersistence::class);
});

test('describeWithConfig() emits the common-superset default MIMEs for providers that opt out', function (): void {
    $stub = new StubConfiguredProvider();
    $registry = buildRegistry([$stub]);

    $rows = $registry->describeWithConfig(99, null);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('stub-configured');
    expect($rows[0]['class'])->toBe(StubConfiguredProvider::class);
    expect($rows[0]['preferred_audio_mimes'])->toBe([
        'audio/webm;codecs=opus',
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/webm',
        'audio/wav',
    ]);
});

test('describeWithConfig() emits per-provider MIMEs declared via #[AcceptedAudioMime]', function (): void {
    // Local fixture mirrors the MiniMax declaration shape so the test
    // stays meaningful for the MiniMax use case without depending on
    // the plugin path repo.
    $provider = new ProviderDeclaringMimes();
    $registry = buildRegistry([$provider]);

    $rows = $registry->describeWithConfig(99, null);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('mime-declaring-stub');
    expect($rows[0]['class'])->toBe(ProviderDeclaringMimes::class);
    expect($rows[0]['preferred_audio_mimes'])->toBe([
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/webm;codecs=opus',
    ]);
});

// Mirrors the MiniMax declaration shape so this test exercises the
// plugin-side ordering without depending on the plugin path repo.
#[Spora\Speech\Attributes\AcceptedAudioMime('audio/ogg;codecs=opus')]
#[Spora\Speech\Attributes\AcceptedAudioMime('audio/mp4')]
#[Spora\Speech\Attributes\AcceptedAudioMime('audio/webm;codecs=opus')]
final class ProviderDeclaringMimes implements SpeechToTextProviderInterface
{
    public function getName(): string
    {
        return 'mime-declaring-stub';
    }
    public function getDisplayName(): string
    {
        return 'MIME Declaring Stub';
    }
    public function isConfigured(): bool
    {
        return true;
    }
    public function bindLabel(string $label): void {}
    public function bindSettings(array $settings): void {}
    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        return new TranscriptionResult('unused');
    }
}
