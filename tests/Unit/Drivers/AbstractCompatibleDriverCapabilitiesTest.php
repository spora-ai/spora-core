<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Spora\Drivers\AbstractCompatibleDriver;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\LLMDriverConfigInterface;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Cover the default {@see AbstractCompatibleDriver::supportsImageInput()}
 * contract — subclasses override based on the configured model name;
 * the base returns false.
 */

test('default supportsImageInput() returns false', function (): void {
    $driver = new DefaultTestDriver(
        apiKey: 'k',
        model: 'whatever',
        baseUrl: 'https://example.invalid',
        httpClient: new MockHttpClient(),
    );

    expect($driver->supportsImageInput())->toBeFalse();
});

test('subclass can override supportsImageInput() to return true', function (): void {
    $driver = new VisionCapableTestDriver(
        apiKey: 'k',
        model: 'whatever',
        baseUrl: 'https://example.invalid',
        httpClient: new MockHttpClient(),
    );

    expect($driver->supportsImageInput())->toBeTrue();
});

test('getModelName() returns the configured model verbatim', function (): void {
    $driver = new DefaultTestDriver(
        apiKey: 'k',
        model: 'my-model-1.2',
        baseUrl: 'https://example.invalid',
        httpClient: new MockHttpClient(),
    );

    expect($driver->getModelName())->toBe('my-model-1.2');
});

test('getDefaultTools() returns an empty list by default', function (): void {
    expect(DefaultTestDriver::getDefaultTools())->toBe([]);
});

test('AbstractCompatibleDriver declares the LLMDriverInterface and LLMDriverConfigInterface contracts', function (): void {
    $driver = new DefaultTestDriver(
        apiKey: 'k',
        model: 'x',
        baseUrl: 'https://example.invalid',
        httpClient: new MockHttpClient(),
    );

    expect($driver)->toBeInstanceOf(LLMDriverInterface::class)
        ->and($driver)->toBeInstanceOf(LLMDriverConfigInterface::class);
});

/**
 * Tiny concrete subclass that uses the base default — the `supportsImageInput()`
 * override is intentionally absent so the test pins the inherited behavior.
 */
final class DefaultTestDriver extends AbstractCompatibleDriver
{
    public function getProviderName(): string
    {
        return 'test_default';
    }

    public static function getName(): string
    {
        return 'test_default';
    }

    public static function getDisplayName(): string
    {
        return 'Test Default Driver';
    }

    public function complete(\Spora\Drivers\ValueObjects\LLMRequest $request): \Spora\Drivers\ValueObjects\LLMResponse
    {
        return new \Spora\Drivers\ValueObjects\LLMResponse(
            content: '',
            toolCalls: [],
            inputTokens: 0,
            outputTokens: 0,
            completionId: '',
        );
    }
}

/**
 * Override `supportsImageInput()` to return true — pins that subclasses
 * are free to extend the default capability flag.
 */
final class VisionCapableTestDriver extends AbstractCompatibleDriver
{
    public function supportsImageInput(): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return 'test_vision';
    }

    public static function getName(): string
    {
        return 'test_vision';
    }

    public static function getDisplayName(): string
    {
        return 'Test Vision Driver';
    }

    public function complete(\Spora\Drivers\ValueObjects\LLMRequest $request): \Spora\Drivers\ValueObjects\LLMResponse
    {
        return new \Spora\Drivers\ValueObjects\LLMResponse(
            content: '',
            toolCalls: [],
            inputTokens: 0,
            outputTokens: 0,
            completionId: '',
        );
    }
}