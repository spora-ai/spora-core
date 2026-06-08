<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Services\LLMConfigSchemaInspector;

test('getDrivers returns one entry per registered driver class', function (): void {
    $inspector = new LLMConfigSchemaInspector([
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);

    $drivers = $inspector->getDrivers();

    expect($drivers)->toHaveCount(2);
    $names = array_column($drivers, 'name');
    expect($names)->toContain('openai_compatible')
        ->and($names)->toContain('anthropic_compatible');
});

test('getDrivers returns the schema with all declared ToolSetting keys', function (): void {
    $inspector = new LLMConfigSchemaInspector([OpenAICompatibleDriver::class]);

    $drivers = $inspector->getDrivers();

    $keys = array_column($drivers[0]['settings_schema'], 'key');
    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model');
});

test('getDrivers returns an empty array when no drivers are registered', function (): void {
    $inspector = new LLMConfigSchemaInspector([]);

    expect($inspector->getDrivers())->toBe([]);
});

test('getDrivers silently skips non-existent driver classes', function (): void {
    /** @var list<class-string<Spora\Drivers\LLMDriverConfigInterface>> $classes */
    $classes = [
        OpenAICompatibleDriver::class,
        'Spora\\Drivers\\NonExistentDriver',
    ];
    $inspector = new LLMConfigSchemaInspector($classes);

    $drivers = $inspector->getDrivers();
    expect($drivers)->toHaveCount(1)
        ->and($drivers[0]['name'])->toBe('openai_compatible');
});

test('getPasswordKeysFor returns only the keys whose type is "password"', function (): void {
    $inspector = new LLMConfigSchemaInspector([OpenAICompatibleDriver::class]);

    $passwordKeys = $inspector->getPasswordKeysFor(OpenAICompatibleDriver::class);

    expect($passwordKeys)->toContain('api_key')
        ->and($passwordKeys)->not->toContain('base_url')
        ->and($passwordKeys)->not->toContain('model');
});

test('getPasswordKeysFor returns an empty array for unknown driver classes', function (): void {
    $inspector = new LLMConfigSchemaInspector([]);

    expect($inspector->getPasswordKeysFor('Spora\\Drivers\\Missing'))->toBe([]);
});

test('getDriverName returns the class name when the driver class is missing', function (): void {
    $inspector = new LLMConfigSchemaInspector([]);

    expect($inspector->getDriverName('Spora\\Drivers\\Missing'))->toBe('Spora\\Drivers\\Missing');
});

test('getSchemaForDriver returns an empty list for an unknown driver class', function (): void {
    $inspector = new LLMConfigSchemaInspector([]);

    expect($inspector->getSchemaForDriver('Spora\\Drivers\\DoesNotExist'))->toBe([]);
});
