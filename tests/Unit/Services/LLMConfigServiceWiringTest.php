<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Services\LLMConfigPersistence;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigSchemaInspector;
use Spora\Services\LLMConfigService;

// Smoke test: assert the facade wires the new collaborators.
// Collaborator classes are `final`, so we cannot extend or Mockery::mock() them.
// Instead we inject real instances and assert identity / behavior to
// prove the facade is using them rather than rolling its own.

test('LLMConfigService wires the three new collaborators by default', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $reflection = new ReflectionClass($service);

    expect($reflection->getProperty('schemaInspector')->getValue($service))
        ->toBeInstanceOf(LLMConfigSchemaInspector::class);

    expect($reflection->getProperty('persistence')->getValue($service))
        ->toBeInstanceOf(LLMConfigPersistence::class);

    expect($reflection->getProperty('preferences')->getValue($service))
        ->toBeInstanceOf(LLMConfigPreferences::class);
});

test('LLMConfigService stores the same collaborator instances that are injected', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $inspector = new LLMConfigSchemaInspector([OpenAICompatibleDriver::class]);
    $persistence = new LLMConfigPersistence($security, $inspector);
    $preferences = new LLMConfigPreferences();

    $service = new LLMConfigService($security, [], $inspector, $persistence, $preferences);

    $reflection = new ReflectionClass($service);
    expect($reflection->getProperty('schemaInspector')->getValue($service))->toBe($inspector);
    expect($reflection->getProperty('persistence')->getValue($service))->toBe($persistence);
    expect($reflection->getProperty('preferences')->getValue($service))->toBe($preferences);
});

test('LLMConfigService uses the injected schema inspector (driver discovery routes through it)', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);

    // An inspector wired with only the Anthropic driver. If the facade
    // constructed its own inspector from the $driverClasses parameter
    // instead of using the injected one, OpenAI would still be present.
    $inspector = new LLMConfigSchemaInspector([AnthropicCompatibleDriver::class]);
    $persistence = new LLMConfigPersistence($security, $inspector);
    $preferences = new LLMConfigPreferences();

    $service = new LLMConfigService($security, [], $inspector, $persistence, $preferences);

    $drivers = $service->getDrivers();
    $names = array_column($drivers, 'name');

    expect($names)->toContain('anthropic_compatible')
        ->and($names)->not->toContain('openai_compatible');
});

test('LLMConfigService uses the injected persistence (encodeSettings routes through it)', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $inspector = new LLMConfigSchemaInspector([OpenAICompatibleDriver::class]);
    $persistence = new LLMConfigPersistence($security, $inspector);
    $preferences = new LLMConfigPreferences();

    $service = new LLMConfigService($security, [], $inspector, $persistence, $preferences);

    // Round-tripping through the real persistence collaborator proves
    // the facade is delegating encode/decode rather than re-implementing.
    $encoded = $service->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-wiring-test',
        'model' => 'gpt-4o',
    ]);
    $decoded = $service->decodeSettings(OpenAICompatibleDriver::class, json_encode($encoded));

    expect($decoded['api_key'])->toBe('sk-wiring-test')
        ->and($decoded['model'])->toBe('gpt-4o');
});

test('decryptSettings delegates to decodeSettings on the persistence collaborator', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $inspector = new LLMConfigSchemaInspector([OpenAICompatibleDriver::class]);
    $persistence = new LLMConfigPersistence($security, $inspector);
    $preferences = new LLMConfigPreferences();

    $service = new LLMConfigService($security, [], $inspector, $persistence, $preferences);

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-alias-test',
        'model' => 'gpt-4o',
    ]);

    $viaDecrypt = $service->decryptSettings(OpenAICompatibleDriver::class, json_encode($encoded));
    $viaDecode  = $service->decodeSettings(OpenAICompatibleDriver::class, json_encode($encoded));

    expect($viaDecrypt)->toBe($viaDecode)
        ->and($viaDecrypt['api_key'])->toBe('sk-alias-test');
});
