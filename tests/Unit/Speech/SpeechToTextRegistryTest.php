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
    return new SpeechToTextRegistry($providers, new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()));
}

test('empty registry — all returns empty, configured returns null, describe returns empty', function (): void {
    $registry = buildRegistry([]);

    expect($registry->all())->toBe([])
        ->and($registry->configuredProvider())->toBeNull()
        ->and($registry->describe())->toBe([null, null, null]);
});

test('configuredProvider() returns the first registered class when no other tier matches', function (): void {
    $registry = buildRegistry([
        new StubConfiguredProvider(),
        new StubUnconfiguredProvider(),
    ]);

    expect($registry->configuredProvider(7))->toBeInstanceOf(StubConfiguredProvider::class);
});

test('describe() returns the registered-class fallback labelled "fallback" when nothing else matches', function (): void {
    $registry = buildRegistry([
        new StubConfiguredProvider(),
        new StubUnconfiguredProvider(),
    ]);

    [$class, $source] = $registry->describe(99);
    expect($class)->toBe(StubConfiguredProvider::class)
        ->and($source)->toBe('fallback');
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

test('Tier 5: first registered class is the fallback when nothing else resolves', function (): void {
    $first = new StubConfiguredProvider();
    $second = new StubUnconfiguredProvider();

    $registry = buildRegistry([$first, $second]);

    [$class, $source] = $registry->describe(99, null);
    expect($class)->toBe(StubConfiguredProvider::class)
        ->and($source)->toBe('fallback');
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
    expect($class)->toBe(StubConfiguredProvider::class)
        ->and($source)->toBe('fallback');
});
