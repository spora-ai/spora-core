<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\LLMDriverConfigInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Tools\Attributes\ToolSetting;

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

test('OpenAICompatibleDriver settings are declared via #[ToolSetting] attributes', function (): void {
    $ref = new ReflectionClass(OpenAICompatibleDriver::class);
    $attrs = $ref->getAttributes(ToolSetting::class);
    expect($attrs)->not->toBeEmpty();
});

test('AnthropicCompatibleDriver settings are declared via #[ToolSetting] attributes', function (): void {
    $ref = new ReflectionClass(AnthropicCompatibleDriver::class);
    $attrs = $ref->getAttributes(ToolSetting::class);
    expect($attrs)->not->toBeEmpty();
});

test('OpenAICompatibleDriver schema contains expected keys', function (): void {
    $ref = new ReflectionClass(OpenAICompatibleDriver::class);
    $keys = array_map(fn($attr) => $attr->newInstance()->key, $ref->getAttributes(ToolSetting::class));

    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model');
});

test('AnthropicCompatibleDriver schema contains expected keys', function (): void {
    $ref = new ReflectionClass(AnthropicCompatibleDriver::class);
    $keys = array_map(fn($attr) => $attr->newInstance()->key, $ref->getAttributes(ToolSetting::class));

    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model');
});

test('OpenAICompatibleDriver::getDefaultTools returns empty array', function (): void {
    expect(OpenAICompatibleDriver::getDefaultTools())->toBeArray()->toBeEmpty();
});

test('AnthropicCompatibleDriver::getDefaultTools returns empty array', function (): void {
    expect(AnthropicCompatibleDriver::getDefaultTools())->toBeArray()->toBeEmpty();
});
