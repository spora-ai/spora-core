<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\LLMDriverConfigInterface;
use Spora\Drivers\OpenAICompatibleDriver;

test('OpenAICompatibleDriver implements LLMDriverConfigInterface', function (): void {
    expect(OpenAICompatibleDriver::class)->toImplement(LLMDriverConfigInterface::class);
});

test('AnthropicCompatibleDriver implements LLMDriverConfigInterface', function (): void {
    expect(AnthropicCompatibleDriver::class)->toImplement(LLMDriverConfigInterface::class);
});

test('OpenAICompatibleDriver::getName returns openai_compatible', function (): void {
    expect(OpenAICompatibleDriver::getName())->toBe('openai_compatible');
});

test('OpenAICompatibleDriver::getDisplayName returns OpenAI Compatible', function (): void {
    expect(OpenAICompatibleDriver::getDisplayName())->toBe('OpenAI Compatible');
});

test('AnthropicCompatibleDriver::getName returns anthropic_compatible', function (): void {
    expect(AnthropicCompatibleDriver::getName())->toBe('anthropic_compatible');
});

test('AnthropicCompatibleDriver::getDisplayName returns Anthropic Compatible', function (): void {
    expect(AnthropicCompatibleDriver::getDisplayName())->toBe('Anthropic Compatible');
});

test('OpenAICompatibleDriver::getSettingsSchema returns non-empty array', function (): void {
    $schema = OpenAICompatibleDriver::getSettingsSchema();
    expect($schema)->toBeArray()
        ->and($schema)->not->toBeEmpty();
});

test('AnthropicCompatibleDriver::getSettingsSchema returns non-empty array', function (): void {
    $schema = AnthropicCompatibleDriver::getSettingsSchema();
    expect($schema)->toBeArray()
        ->and($schema)->not->toBeEmpty();
});

test('OpenAICompatibleDriver schema contains expected keys', function (): void {
    $schema = OpenAICompatibleDriver::getSettingsSchema();
    $keys = array_map(fn($field) => $field->key, $schema);

    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model');
});

test('AnthropicCompatibleDriver schema contains expected keys', function (): void {
    $schema = AnthropicCompatibleDriver::getSettingsSchema();
    $keys = array_map(fn($field) => $field->key, $schema);

    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model');
});

test('OpenAICompatibleDriver schema fields have correct scope', function (): void {
    $schema = OpenAICompatibleDriver::getSettingsSchema();
    foreach ($schema as $field) {
        expect($field->scope)->toBe('global');
    }
});

test('AnthropicCompatibleDriver schema fields have correct scope', function (): void {
    $schema = AnthropicCompatibleDriver::getSettingsSchema();
    foreach ($schema as $field) {
        expect($field->scope)->toBe('global');
    }
});

test('OpenAICompatibleDriver::getDefaultTools returns empty array', function (): void {
    expect(OpenAICompatibleDriver::getDefaultTools())->toBeArray()->toBeEmpty();
});

test('AnthropicCompatibleDriver::getDefaultTools returns empty array', function (): void {
    expect(AnthropicCompatibleDriver::getDefaultTools())->toBeArray()->toBeEmpty();
});
