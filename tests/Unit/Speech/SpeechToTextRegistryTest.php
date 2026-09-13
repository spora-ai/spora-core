<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Pin the registry selection rules: first-configured-wins, describe()
 * shape, empty-list behaviour, and the OpenAiCompatibleTranscriber
 * per-config label binding.
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

/**
 * Class-level provider that opts into the optional bindLabel() hook so the
 * registry can resolve a per-agent `display_name` ToolSetting — mirrors
 * how {@see Spora\Plugins\Muse\MuseTranscribeProvider} does it.
 */
final class StubRelabelledProvider implements SpeechToTextProviderInterface
{
    private ?string $boundLabel = null;

    public function getName(): string
    {
        return $this->boundLabel ?? 'relabelled';
    }
    public function getDisplayName(): string
    {
        return $this->boundLabel ?? 'Relabelled Provider';
    }
    public function isConfigured(): bool
    {
        return true;
    }
    public function bindLabel(string $label): void
    {
        $this->boundLabel = $label;
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

/**
 * Build a registry factory: providers + a ToolConfigService mock that
 * returns the same settings for every (class, agentId, userId) tuple.
 *
 * @param list<SpeechToTextProviderInterface> $providers
 */
function buildRegistry(array $providers, array $settings = [], array $globalSettings = [], ?int $globalConfigId = null): SpeechToTextRegistry
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);
    $config->shouldReceive('getGlobalSettings')->andReturn($globalSettings);
    $idResolver = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolver->shouldReceive('globalConfigId')->andReturn($globalConfigId);
    return new SpeechToTextRegistry($providers, $config, $idResolver);
}

test('empty registry — all, configured, describe all return empty', function (): void {
    $registry = buildRegistry([]);

    expect($registry->all())->toBe([])
        ->and($registry->configuredProvider())->toBeNull()
        ->and($registry->describe())->toBe([]);
});

test('configuredProvider() returns null when every provider reports unconfigured', function (): void {
    $registry = buildRegistry([
        new StubUnconfiguredProvider(),
        new StubUnconfiguredProvider(),
    ]);

    expect($registry->configuredProvider())->toBeNull();
});

test('configuredProvider() returns the first configured entry (insertion order wins)', function (): void {
    $configured = new StubConfiguredProvider();
    $unconfigured = new StubUnconfiguredProvider();

    $registry = buildRegistry([$unconfigured, $configured]);

    expect($registry->configuredProvider())->toBe($configured);
});

test('describe() emits name + display_name + configured + has_global_default + config_id for every provider, in order', function (): void {
    $a = new StubConfiguredProvider();
    $b = new StubUnconfiguredProvider();

    $registry = buildRegistry([$a, $b]);

    expect($registry->describe())->toBe([
        ['name' => 'stub-configured',   'display_name' => 'Stub Configured',   'configured' => true,  'has_global_default' => false, 'config_id' => null, 'effective_class' => StubConfiguredProvider::class,   'effective_source' => 'fallback'],
        ['name' => 'stub-unconfigured', 'display_name' => 'Stub Unconfigured', 'configured' => false, 'has_global_default' => false, 'config_id' => null, 'effective_class' => StubConfiguredProvider::class,   'effective_source' => 'fallback'],
    ]);
});

test('all() returns the providers in their constructor order', function (): void {
    $a = new StubConfiguredProvider();
    $b = new StubUnconfiguredProvider();

    $registry = buildRegistry([$a, $b]);

    expect($registry->all())->toBe([$a, $b]);
});

test('OpenAiCompatibleTranscriber describe() binds the resolved display_name and reports configured when api_key is non-empty', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));
    $registry = buildRegistry([$oai], [
        'display_name' => 'Mistral Voxtral',
        'api_key'      => 'sk-test',
    ]);

    $rows = $registry->describe(42, null);

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBe([
        'name'               => 'Mistral Voxtral',
        'display_name'       => 'Mistral Voxtral',
        'configured'         => true,
        'has_global_default' => false,
        'config_id'          => null,
        'effective_class'    => OpenAiCompatibleTranscriber::class,
        'effective_source'   => 'fallback',
    ]);
    expect($oai->getName())->toBe('Mistral Voxtral');
    expect($oai->getDisplayName())->toBe('Mistral Voxtral');
});

test('OpenAiCompatibleTranscriber describe() falls back to class-level defaults when no effective config resolves', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));
    $registry = buildRegistry([$oai], []); // no display_name, no api_key

    $rows = $registry->describe(7, null);

    expect($rows[0])->toBe([
        'name'               => 'openai_compatible',
        'display_name'       => 'OpenAI Compatible',
        'configured'         => false,
        'has_global_default' => false,
        'config_id'          => null,
        'effective_class'    => OpenAiCompatibleTranscriber::class,
        'effective_source'   => 'fallback',
    ]);
});

test('OpenAiCompatibleTranscriber configuredProvider() skips providers whose effective api_key is empty', function (): void {
    $first = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));
    $second = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    // The registry walks providers in order; each call to
    // getEffectiveSettings returns the SAME map in this stub, but the
    // real cascade is the caller's responsibility — for the unit test
    // we just need to verify the registry skips when api_key is empty
    // and picks the next provider once the api_key resolves.
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')
        ->andReturn(
            // First provider — empty key, must be skipped.
            ['display_name' => 'first', 'api_key' => ''],
            // Second provider — non-empty key, must be selected.
            ['display_name' => 'second', 'api_key' => 'sk-test'],
        );
    $registry = new SpeechToTextRegistry([$first, $second], $config);

    expect($registry->configuredProvider(99, null))->toBe($second);
    expect($second->getName())->toBe('second');
});

test('OpenAiCompatibleTranscriber configuredProvider() returns the first provider whose api_key is non-empty', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $registry = buildRegistry([$oai], ['display_name' => 'only', 'api_key' => 'sk-test']);

    expect($registry->configuredProvider())->toBe($oai);
    expect($oai->getName())->toBe('only');
});

test('OpenAiCompatibleTranscriber has_global_default reflects ToolConfigService::getGlobalSettings()', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $configWithGlobal = Mockery::mock(ToolConfigService::class);
    $configWithGlobal->shouldReceive('getEffectiveSettings')->andReturn([
        'display_name' => 'with global',
        'api_key'      => 'sk-1',
    ]);
    $configWithGlobal->shouldReceive('getGlobalSettings')->andReturn([
        'display_name' => 'with global',
        'api_key'      => 'sk-1',
    ]);
    $idResolverWith = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolverWith->shouldReceive('globalConfigId')->andReturn(7);
    $withGlobal = new SpeechToTextRegistry([$oai], $configWithGlobal, $idResolverWith);
    expect($withGlobal->describe(0, null)[0]['has_global_default'])->toBeTrue();

    $oai2 = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));
    $configWithoutGlobal = Mockery::mock(ToolConfigService::class);
    $configWithoutGlobal->shouldReceive('getEffectiveSettings')->andReturn([
        'display_name' => 'no global',
        'api_key'      => 'sk-2',
    ]);
    $configWithoutGlobal->shouldReceive('getGlobalSettings')->andReturn([]);
    $idResolverWithout = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolverWithout->shouldReceive('globalConfigId')->andReturn(null);
    $withoutGlobal = new SpeechToTextRegistry([$oai2], $configWithoutGlobal, $idResolverWithout);
    expect($withoutGlobal->describe(0, null)[0]['has_global_default'])->toBeFalse();
});

test('class-level provider rows report has_global_default=false (only OpenAiCompatibleTranscriber consults the global settings table)', function (): void {
    $stub = new StubConfiguredProvider();
    $registry = buildRegistry([$stub]);

    expect($registry->describe()[0]['has_global_default'])->toBeFalse();
});

test('describe() and configuredProvider() route no-arg calls through with userId=0, agentId=null', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')
        ->with($oai::class, 0, 0)
        ->andReturn(['display_name' => 'no-arg', 'api_key' => 'sk-no-arg']);
    $config->shouldReceive('getGlobalSettings')
        ->with($oai::class)
        ->andReturn(['display_name' => 'no-arg']);
    $idResolver = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolver->shouldReceive('globalConfigId')
        ->with($oai::class)
        ->andReturn(null);
    $registry = new SpeechToTextRegistry([$oai], $config, $idResolver);

    $rows = $registry->describe();
    expect($rows[0]['name'])->toBe('no-arg')
        ->and($rows[0]['configured'])->toBeTrue();

    expect($registry->configuredProvider())->toBe($oai);
});

test('describe() populates config_id with the global row id when one exists for OpenAiCompatibleTranscriber', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));
    $registry = buildRegistry([$oai], [
        'display_name' => 'Mistral Voxtral',
        'api_key'      => 'sk-test',
    ], globalSettings: ['display_name' => 'Mistral Voxtral'], globalConfigId: 42);

    $rows = $registry->describe(0, null);

    expect($rows[0]['config_id'])->toBe(42);
});

test('describe() leaves config_id as null when no global row exists for OpenAiCompatibleTranscriber', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));
    $registry = buildRegistry([$oai], [], [], null);

    $rows = $registry->describe(0, null);

    expect($rows[0]['config_id'])->toBeNull();
});

test('describe() leaves config_id as null for class-level providers that never write to tool_configurations', function (): void {
    $stub = new StubConfiguredProvider();
    $registry = buildRegistry([$stub], [], [], 7);

    $rows = $registry->describe(0, null);

    expect($rows[0]['config_id'])->toBeNull()
        ->and($rows[0]['name'])->toBe('stub-configured');
});

test('class-level provider with bindLabel() — resolved display_name from settings overrides the class-level default', function (): void {
    // Mirrors how the Muse plugin's MuseTranscribeProvider declares
    // a `display_name` ToolSetting and the registry resolves per-agent
    // effective settings before reading getDisplayName().
    $provider = new StubRelabelledProvider();
    $registry = buildRegistry([$provider], ['display_name' => 'Agent Muse Voice']);

    $rows = $registry->describe(42, null);

    expect($rows[0])->toBe([
        'name'               => 'Agent Muse Voice',
        'display_name'       => 'Agent Muse Voice',
        'configured'         => true,
        'has_global_default' => false,
        'config_id'          => null,
        'effective_class'    => StubRelabelledProvider::class,
        'effective_source'   => 'fallback',
    ]);
});

test('class-level provider with bindLabel() — empty / whitespace display_name falls through to the class-level default', function (): void {
    $provider = new StubRelabelledProvider();
    $registry = buildRegistry([$provider], ['display_name' => '   ']);

    $rows = $registry->describe(0, null);

    expect($rows[0]['name'])->toBe('relabelled')
        ->and($rows[0]['display_name'])->toBe('Relabelled Provider');
});

test('class-level provider without bindLabel() — registry skips the rebind (BC with existing class-level providers)', function (): void {
    // StubConfiguredProvider does not implement bindLabel(). The
    // registry's method_exists gate skips the call so the provider
    // keeps its static names even when a `display_name` setting
    // resolves from config.
    $provider = new StubConfiguredProvider();
    $registry = buildRegistry([$provider], ['display_name' => 'ignored']);

    $rows = $registry->describe(0, null);

    expect($rows[0]['name'])->toBe('stub-configured')
        ->and($rows[0]['display_name'])->toBe('Stub Configured');
});

test('configuredProvider() — Tier 1: agent override wins over the configured loop', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    // Effective settings are populated with an api_key so the OpenAiCompatible
    // provider resolves as configured once the cascade reaches tier 1.
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'display_name' => 'Override', 'api_key' => 'sk-agent',
    ]);

    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $idResolver = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolver->shouldReceive('globalConfigId')->andReturn(null);

    // Insert an agent (FK target for agent_tool_overrides.agent_id) and
    // an override row for OpenAiCompatibleTranscriber.
    $ownerUserId = 11;
    $ownerPrincipalId = createUserPrincipalPublic($ownerUserId);
    $agentId = (int) Capsule::table('agents')->insertGetId([
        'name'         => 'SpCAgent',
        'principal_id' => $ownerPrincipalId,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('agent_tool_overrides')->insert([
        'agent_id'   => $agentId,
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'settings'   => '{"display_name":"Agent Override","api_key":"sk-agent"}',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $registry = new SpeechToTextRegistry([$oai], $config, $idResolver, $principalService);

    expect($registry->configuredProvider(null, $agentId))->toBe($oai);
});

test('configuredProvider() — Tier 2: user preference used when no agent override', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'display_name' => 'User pref', 'api_key' => 'sk-user',
    ]);

    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $idResolver = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolver->shouldReceive('globalConfigId')->andReturn(null);

    $userId = 7;
    $userPrincipalId = createUserPrincipalPublic($userId);

    // User-scope row for OpenAiCompatibleTranscriber exists.
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $userPrincipalId,
        'tool_class'   => OpenAiCompatibleTranscriber::class,
        'settings'     => '{"display_name":"User pref","api_key":"sk-user"}',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    // User preference points at OpenAiCompatibleTranscriber.
    Capsule::table('principal_preferences')->insert([
        'principal_id'                    => $userPrincipalId,
        'preferred_speech_provider_class' => OpenAiCompatibleTranscriber::class,
        'created_at'                      => date('Y-m-d H:i:s'),
        'updated_at'                      => date('Y-m-d H:i:s'),
    ]);

    $registry = new SpeechToTextRegistry([$oai], $config, $idResolver, $principalService);

    expect($registry->configuredProvider($userId, null))->toBe($oai);
});

test('configuredProvider() — Tier 3: group preference used when no user preference', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'display_name' => 'Group pref', 'api_key' => 'sk-group',
    ]);

    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $idResolver = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolver->shouldReceive('globalConfigId')->andReturn(null);

    $userId = 7;
    createUserPrincipalPublic($userId);
    $ownerUserId = 8;
    createUserPrincipalPublic($ownerUserId);

    // Owner creates a group and adds user 7 as a member.
    $groupService = new Spora\Services\GroupService($principalService);
    $group = $groupService->createGroup($ownerUserId, 'SpCGrpReg');
    $groupService->addMember((int) $group->id, $userId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerUserId);

    $groupPrincipalId = (int) Capsule::table('principals')
        ->where('type', Spora\Models\Principal::TYPE_GROUP)
        ->where('group_id', $group->id)
        ->value('id');

    // Group-scope row for OpenAiCompatibleTranscriber exists.
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $groupPrincipalId,
        'tool_class'   => OpenAiCompatibleTranscriber::class,
        'settings'     => '{"display_name":"Group pref","api_key":"sk-group"}',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    // Group preference points at OpenAiCompatibleTranscriber.
    Capsule::table('principal_preferences')->insert([
        'principal_id'                    => $groupPrincipalId,
        'preferred_speech_provider_class' => OpenAiCompatibleTranscriber::class,
        'created_at'                      => date('Y-m-d H:i:s'),
        'updated_at'                      => date('Y-m-d H:i:s'),
    ]);

    $registry = new SpeechToTextRegistry([$oai], $config, $idResolver, $principalService);

    expect($registry->configuredProvider($userId, null))->toBe($oai);
});

test('configuredProvider() — Tier 4: global is_default used when no preferences set', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'display_name' => 'Global default', 'api_key' => 'sk-global',
    ]);
    $config->shouldReceive('getGlobalSettings')->andReturn([
        'display_name' => 'Global default', 'api_key' => 'sk-global',
    ]);

    $idResolver = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolver->shouldReceive('globalConfigId')->andReturn(99);

    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());

    // Global row marked as default.
    Capsule::table('tool_configurations')->insert([
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'tool_name'  => 'openai_compatible',
        'settings'   => '{"display_name":"Global default","api_key":"sk-global"}',
        'is_default' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $registry = new SpeechToTextRegistry([$oai], $config, $idResolver, $principalService);

    expect($registry->configuredProvider(null, null))->toBe($oai);
});

test('configuredProvider() — Tier 5: first-configured-wins when nothing is set', function (): void {
    $configured = new StubConfiguredProvider();
    $unconfigured = new StubUnconfiguredProvider();

    $registry = buildRegistry([$unconfigured, $configured]);

    expect($registry->configuredProvider())->toBe($configured);
});

test('configuredProvider() — Tier 2/3 fall through when preferred class is unregistered', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new MockHttpClient(), Mockery::mock(ToolConfigService::class));

    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([]);
    $config->shouldReceive('getGlobalSettings')->andReturn([]);

    $idResolver = Mockery::mock(Spora\Services\ToolConfigIdResolver::class);
    $idResolver->shouldReceive('globalConfigId')->andReturn(null);

    $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());

    $userId = 9;
    $userPrincipalId = createUserPrincipalPublic($userId);

    // User preference points at an UNREGISTERED class.
    Capsule::table('principal_preferences')->insert([
        'principal_id'                    => $userPrincipalId,
        'preferred_speech_provider_class' => 'SomeOld\\Class\\NotRegistered',
        'created_at'                      => date('Y-m-d H:i:s'),
        'updated_at'                      => date('Y-m-d H:i:s'),
    ]);

    $registry = new SpeechToTextRegistry([$oai], $config, $idResolver, $principalService);

    // Tier 2/3 fall through; tier 4 has no global default; tier 5
    // returns null because no provider is configured.
    expect($registry->configuredProvider($userId, null))->toBeNull();
});
