<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Http\Exceptions\SpeechProviderConfigException;
use Spora\Models\Principal;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigService;
use Spora\Services\SpeechProviderConfigValidator;
use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Speech\TranscriptionResult;

/**
 * Wrap {@see SpeechProviderConfigService} so the tests don't have to
 * hand-roll the validator for every fixture.
 */
function buildService(
    ToolConfigService $toolConfig,
    SpeechToTextRegistry $registry,
    PrincipalService $principalService,
): SpeechProviderConfigService {
    return new SpeechProviderConfigService(
        $toolConfig,
        $registry,
        $principalService,
        new SpeechProviderConfigValidator($registry),
    );
}

/**
 * Stub provider that opts into the `instanceof` gate
 * {@see SpeechToTextRegistry::configuredProvider()} uses to call
 * {@see OpenAiCompatibleTranscriber::bindLabel()} — lets us assert
 * that `getSchema()` walks `#[ToolSetting]` attributes on a class that
 * isn't OpenAiCompatibleTranscriber.
 */
#[Spora\Tools\Attributes\ToolSetting(
    key: 'api_key',
    label: 'API Key',
    type: 'password',
    required: true,
)]
#[Spora\Tools\Attributes\ToolSetting(
    key: 'ffmpeg_binary',
    label: 'ffmpeg path',
    type: 'text',
    required: false,
    default: 'ffmpeg',
)]
final class StubSpeechProviderWithSettings implements SpeechToTextProviderInterface
{
    public function getName(): string
    {
        return 'stub_settings';
    }
    public function getDisplayName(): string
    {
        return 'Stub Settings';
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
        throw new InvalidAudioException('not used');
    }
}

test('listConfigs returns every global config to an admin (one row per registered provider class)', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldReceive('globalConfigId')
        ->andReturnUsing(static fn(string $class): ?int => $class === OpenAiCompatibleTranscriber::class ? 7 : null);
    $toolConfig->shouldReceive('getGlobalSettings')
        ->andReturnUsing(static fn(string $class): array => $class === OpenAiCompatibleTranscriber::class
            ? ['display_name' => 'Mistral Voxtral', 'api_key' => 'sk-x']
            : []);
    $toolConfig->shouldReceive('maskForApi')
        ->andReturnUsing(static fn(array $settings): array => $settings);
    $toolConfig->shouldReceive('fetchCreatedAt')->andReturnUsing(static fn(): ?string => null);
    $toolConfig->shouldReceive('fetchUpdatedAt')->andReturnUsing(static fn(): ?string => null);

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            $toolConfig,
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $rows = $service->listConfigs(1, true);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe(7);
    expect($rows[0]['provider_class'])->toBe(OpenAiCompatibleTranscriber::class);
    expect($rows[0]['scope'])->toBe('global');
});

test('listConfigs returns only the caller user-scoped configs to a non-admin', function (): void {
    // Register OpenAiCompatibleTranscriber so the registry has a known class.
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldReceive('getPrincipalSettings')->andReturn(['display_name' => 'Mine', 'api_key' => 'sk-x']);
    $toolConfig->shouldReceive('maskForApi')->andReturnUsing(static fn(array $settings): array => $settings);

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            $toolConfig,
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    // Materialise the user-principal and insert a tool_user_settings row.
    $userId = 11;
    $principalId = createUserPrincipalPublic($userId);

    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId,
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'settings' => '{"display_name":"Mine","api_key":"sk-x"}',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $rows = $service->listConfigs($userId, false);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['scope'])->toBe('user');
    expect($rows[0]['provider_class'])->toBe(OpenAiCompatibleTranscriber::class);
});

test('getSchema enumerates registered provider classes and walks #[ToolSetting] attributes', function (): void {
    $oai = new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class));
    $stub = new StubSpeechProviderWithSettings();
    $toolConfig = Mockery::mock(ToolConfigService::class);

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry([$oai, $stub], $toolConfig),
        new PrincipalService(new PrincipalResolver()),
    );

    $schema = $service->getSchema();

    expect($schema)->toHaveCount(2);

    $classes = array_column($schema, 'class');
    expect($classes)->toContain(OpenAiCompatibleTranscriber::class);
    expect($classes)->toContain(StubSpeechProviderWithSettings::class);

    $oaiRow = $schema[array_search(OpenAiCompatibleTranscriber::class, $classes, true)];
    $keys = array_column($oaiRow['settings_schema'], 'key');
    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model')
        ->and($keys)->toContain('display_name');
});

test('upsertConfig rejects global scope for non-admin callers with a forbidden exception', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldIgnoreMissing();

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            $toolConfig,
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $service->upsertConfig(
        userId: 1,
        isAdmin: false,
        providerClass: OpenAiCompatibleTranscriber::class,
        scope: 'global',
        settings: ['api_key' => 'sk-x'],
    );
})->throws(SpeechProviderConfigException::class, 'admin');

test('upsertConfig throws notFound when provider_class is not registered', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldIgnoreMissing();
    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry([], $toolConfig),
        new PrincipalService(new PrincipalResolver()),
    );

    $service->upsertConfig(
        userId: 1,
        isAdmin: true,
        providerClass: 'NotAReal\\Class',
        scope: 'global',
        settings: [],
    );
})->throws(SpeechProviderConfigException::class);

test('upsertConfig writes global settings via putGlobalSettings when scope=global', function (): void {
    $captured = ['class' => null, 'settings' => null];

    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldReceive('putGlobalSettings')
        ->andReturnUsing(function (string $class, array $settings) use (&$captured): void {
            $captured['class'] = $class;
            $captured['settings'] = $settings;
        });
    $toolConfig->shouldReceive('globalConfigId')
        ->andReturnUsing(static fn(string $class): ?int => $class === OpenAiCompatibleTranscriber::class ? 42 : null);
    $toolConfig->shouldReceive('getGlobalSettings')
        ->andReturnUsing(static fn(): array => ['display_name' => 'X', 'api_key' => 'sk-y', 'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1']);
    $toolConfig->shouldReceive('maskForApi')
        ->andReturnUsing(static fn(array $settings): array => $settings);

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            $toolConfig,
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $result = $service->upsertConfig(
        userId: 1,
        isAdmin: true,
        providerClass: OpenAiCompatibleTranscriber::class,
        scope: 'global',
        settings: ['display_name' => 'X', 'api_key' => 'sk-y', 'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1'],
    );

    expect($captured['class'])->toBe(OpenAiCompatibleTranscriber::class);
    expect($captured['settings'])->toBe(['display_name' => 'X', 'api_key' => 'sk-y', 'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1']);
    expect($result['id'])->toBe(42);
    expect($result['scope'])->toBe('global');
});

test('upsertConfig resolves the caller principal and writes user settings when scope=user', function (): void {
    $userId = 42;
    $principalId = createUserPrincipalPublic($userId);

    $captured = ['class' => null, 'principal' => null, 'settings' => null];

    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldReceive('putPrincipalSettings')
        ->andReturnUsing(function (string $class, int $p, array $settings) use (&$captured): array {
            $captured['class'] = $class;
            $captured['principal'] = $p;
            $captured['settings'] = $settings;
            return $settings;
        });
    $toolConfig->shouldReceive('getPrincipalSettingsId')
        ->andReturnUsing(static fn(): int => 99);
    $toolConfig->shouldReceive('getPrincipalSettings')
        ->andReturnUsing(static fn(): array => ['display_name' => 'Mine', 'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1']);
    $toolConfig->shouldReceive('maskForApi')
        ->andReturnUsing(static fn(array $settings): array => $settings);

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            $toolConfig,
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $result = $service->upsertConfig(
        userId: $userId,
        isAdmin: false,
        providerClass: OpenAiCompatibleTranscriber::class,
        scope: 'user',
        settings: ['display_name' => 'Mine', 'api_key' => 'sk-mine', 'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1'],
    );

    expect($captured['class'])->toBe(OpenAiCompatibleTranscriber::class);
    expect($captured['principal'])->toBe($principalId);
    expect($captured['settings'])->toBe(['display_name' => 'Mine', 'api_key' => 'sk-mine', 'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1']);
    expect($result['id'])->toBe(99);
    expect($result['scope'])->toBe('user');
    expect($result['principal_id'])->toBe($principalId);
});

test('upsertConfig rejects unknown settings keys with a validation exception', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldIgnoreMissing();

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            Mockery::mock(ToolConfigService::class),
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $service->upsertConfig(
        userId: 1,
        isAdmin: true,
        providerClass: OpenAiCompatibleTranscriber::class,
        scope: 'global',
        settings: ['api_key' => 'sk-x', 'base_url' => 'https://api.openai.com/v1', 'not_a_real_key' => 'whatever'],
    );
})->throws(SpeechProviderConfigException::class, 'not_a_real_key');

test('upsertConfig rejects values that fail the declared regex validation', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldIgnoreMissing();

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            Mockery::mock(ToolConfigService::class),
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $service->upsertConfig(
        userId: 1,
        isAdmin: true,
        providerClass: OpenAiCompatibleTranscriber::class,
        scope: 'global',
        settings: ['api_key' => 'sk-x', 'base_url' => 'not-a-url'],
    );
})->throws(SpeechProviderConfigException::class);

test('updateConfig rejects non-admin updates to a global config', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldIgnoreMissing();

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            $toolConfig,
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    // Insert a row directly so the id is real.
    $id = (int) Capsule::table('tool_configurations')->insertGetId([
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'tool_name' => 'openai_compatible',
        'settings' => '{}',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $service->updateConfig(
        userId: 1,
        isAdmin: false,
        id: $id,
        settings: ['api_key' => 'sk-x'],
    );
})->throws(SpeechProviderConfigException::class, 'admin');

test('deleteConfig calls deleteGlobalSettings when the row is global and caller is admin', function (): void {
    $captured = ['class' => null];
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldReceive('deleteGlobalSettings')
        ->andReturnUsing(function (string $class) use (&$captured): void {
            $captured['class'] = $class;
        });

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            Mockery::mock(ToolConfigService::class),
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $id = (int) Capsule::table('tool_configurations')->insertGetId([
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'tool_name' => 'openai_compatible',
        'settings' => '{}',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $ok = $service->deleteConfig(
        userId: 1,
        isAdmin: true,
        id: $id,
    );

    expect($ok)->toBeTrue();
    expect($captured['class'])->toBe(OpenAiCompatibleTranscriber::class);
});

test('deleteConfig calls deletePrincipalSettings when the row is user-scoped', function (): void {
    $userId = 7;
    $principalId = createUserPrincipalPublic($userId);

    $captured = ['class' => null, 'principal' => null];
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldReceive('deletePrincipalSettings')
        ->andReturnUsing(function (string $class, int $p) use (&$captured): void {
            $captured['class'] = $class;
            $captured['principal'] = $p;
        });

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new Symfony\Component\HttpClient\MockHttpClient(), Mockery::mock(ToolConfigService::class))],
            Mockery::mock(ToolConfigService::class),
        ),
        new PrincipalService(new PrincipalResolver()),
    );

    $id = (int) Capsule::table('tool_user_settings')->insertGetId([
        'principal_id' => $principalId,
        'tool_class' => OpenAiCompatibleTranscriber::class,
        'settings' => '{}',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $ok = $service->deleteConfig(
        userId: $userId,
        isAdmin: false,
        id: $id,
    );

    expect($ok)->toBeTrue();
    expect($captured['class'])->toBe(OpenAiCompatibleTranscriber::class);
    expect($captured['principal'])->toBe($principalId);
});

test('deleteConfig returns false for a non-existent id', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldIgnoreMissing();

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry([], $toolConfig),
        new PrincipalService(new PrincipalResolver()),
    );

    expect($service->deleteConfig(1, true, 999_999))->toBeFalse();
});

test('getConfig returns null for an unknown id', function (): void {
    $toolConfig = Mockery::mock(ToolConfigService::class);
    $toolConfig->shouldIgnoreMissing();

    $service = buildService(
        $toolConfig,
        new SpeechToTextRegistry([], $toolConfig),
        new PrincipalService(new PrincipalResolver()),
    );

    expect($service->getConfig(1, true, 999_999))->toBeNull();
});
