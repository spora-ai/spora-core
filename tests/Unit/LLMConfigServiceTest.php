<?php

declare(strict_types=1);

use Spora\Core\SecurityManagerInterface;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Services\LLMConfigService;

test('getDrivers returns all registered drivers', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);

    $drivers = $service->getDrivers();

    expect($drivers)->toBeArray()
        ->and(count($drivers))->toBe(2);

    $names = array_column($drivers, 'name');
    expect($names)->toContain('openai_compatible')
        ->and($names)->toContain('anthropic_compatible');
});

test('getDrivers returns empty array when no drivers registered', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, []);

    expect($service->getDrivers())->toBeEmpty();
});

test('getDrivers excludes non-existent classes', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        'Spora\Drivers\NonExistentDriver',
    ]);

    $drivers = $service->getDrivers();

    expect(count($drivers))->toBe(1)
        ->and($drivers[0]['name'])->toBe('openai_compatible');
});

test('getDrivers returns correct display names', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);

    $drivers = $service->getDrivers();

    $byName = [];
    foreach ($drivers as $d) {
        $byName[$d['name']] = $d;
    }

    expect($byName['openai_compatible']['display_name'])->toBe('OpenAI Compatible')
        ->and($byName['anthropic_compatible']['display_name'])->toBe('Anthropic Compatible');
});

test('getDrivers returns settings_schema for each driver', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
    ]);

    $drivers = $service->getDrivers();

    expect($drivers[0]['settings_schema'])->toBeArray()
        ->and($drivers[0]['settings_schema'])->not->toBeEmpty();

    $keys = array_column($drivers[0]['settings_schema'], 'key');
    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model');
});

test('encryptSettings returns storage string', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);

    $service = new LLMConfigService($security, []);

    $result = $service->encryptSettings(['api_key' => 'sk-test']);

    expect($result)->toBeString()
        ->and(strlen($result))->toBeGreaterThan(0);

    // Verify round-trip
    $decrypted = $service->decryptSettings($result);
    expect($decrypted['api_key'])->toBe('sk-test');
});

test('decryptSettings returns array', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, []);

    $encrypted = $security->encrypt('{"api_key":"sk-test"}');
    $storageString = $encrypted->toStorageString();

    $result = $service->decryptSettings($storageString);

    expect($result)->toBe(['api_key' => 'sk-test']);
});

test('maskForApi replaces password fields with ***', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, []);

    $settings = [
        'api_key' => 'sk-secret',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o',
    ];

    $schema = [
        ['key' => 'api_key', 'type' => 'password'],
        ['key' => 'base_url', 'type' => 'text'],
        ['key' => 'model', 'type' => 'text'],
    ];

    $masked = $service->maskForApi($settings, $schema);

    expect($masked['api_key'])->toBe('***')
        ->and($masked['base_url'])->toBe('https://api.openai.com/v1')
        ->and($masked['model'])->toBe('gpt-4o');
});

test('maskForApi leaves empty password fields unchanged', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, []);

    $settings = [
        'api_key' => '',
    ];

    $schema = [
        ['key' => 'api_key', 'type' => 'password'],
    ];

    $masked = $service->maskForApi($settings, $schema);

    expect($masked['api_key'])->toBe('');
});
