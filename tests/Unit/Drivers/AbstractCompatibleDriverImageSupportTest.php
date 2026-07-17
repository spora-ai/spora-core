<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Spora\Drivers\AbstractCompatibleDriver;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * Concrete subclass for exercising the abstract driver in isolation.
 */
final class StubDriver extends AbstractCompatibleDriver
{
    public function __construct(
        string              $apiKey,
        string              $model,
        string              $baseUrl,
        HttpClientInterface $httpClient,
        protected readonly bool $heuristic = false,
        ?bool               $supportsImageInput = null,
    ) {
        parent::__construct($apiKey, $model, $baseUrl, $httpClient, null, null, $supportsImageInput);
    }

    public function getProviderName(): string
    {
        return 'stub';
    }
    public static function getName(): string
    {
        return 'stub';
    }
    public static function getDisplayName(): string
    {
        return 'Stub';
    }
    public function complete(\Spora\Drivers\ValueObjects\LLMRequest $request): \Spora\Drivers\ValueObjects\LLMResponse
    {
        return new \Spora\Drivers\ValueObjects\LLMResponse(content: '', toolCalls: [], inputTokens: 0, outputTokens: 0, completionId: '');
    }

    protected function modelBasedSupportsImageInput(): bool
    {
        return $this->heuristic;
    }
}

test('abstract driver returns true when toggle is true regardless of model heuristic', function (): void {
    $driver = new StubDriver(apiKey: '', model: 'foo', baseUrl: '', httpClient: new MockHttpClient(), heuristic: false, supportsImageInput: true);
    expect($driver->supportsImageInput())->toBeTrue();
});

test('abstract driver returns false when toggle is false regardless of model heuristic', function (): void {
    $driver = new StubDriver(apiKey: '', model: 'foo', baseUrl: '', httpClient: new MockHttpClient(), heuristic: true, supportsImageInput: false);
    expect($driver->supportsImageInput())->toBeFalse();
});

test('abstract driver falls back to model heuristic when toggle is null', function (): void {
    $truthy = new StubDriver(apiKey: '', model: 'foo', baseUrl: '', httpClient: new MockHttpClient(), heuristic: true, supportsImageInput: null);
    expect($truthy->supportsImageInput())->toBeTrue();
    $falsy = new StubDriver(apiKey: '', model: 'foo', baseUrl: '', httpClient: new MockHttpClient(), heuristic: false, supportsImageInput: null);
    expect($falsy->supportsImageInput())->toBeFalse();
});

test('OpenAI driver respects toggle override', function (): void {
    $visionModel = new OpenAICompatibleDriver(
        apiKey: '',
        model: 'gpt-4o',
        baseUrl: '',
        httpClient: new MockHttpClient(),
        supportsImageInput: false,
    );
    expect($visionModel->supportsImageInput())->toBeFalse();

    $nonVisionModel = new OpenAICompatibleDriver(
        apiKey: '',
        model: 'gpt-3.5-turbo',
        baseUrl: '',
        httpClient: new MockHttpClient(),
        supportsImageInput: true,
    );
    expect($nonVisionModel->supportsImageInput())->toBeTrue();
});

test('OpenAI driver falls back to model heuristic when toggle is null', function (): void {
    expect((new OpenAICompatibleDriver(apiKey: '', model: 'gpt-4o', baseUrl: '', httpClient: new MockHttpClient(), supportsImageInput: null))->supportsImageInput())->toBeTrue();
    expect((new OpenAICompatibleDriver(apiKey: '', model: 'gpt-3.5-turbo', baseUrl: '', httpClient: new MockHttpClient(), supportsImageInput: null))->supportsImageInput())->toBeFalse();
    expect((new OpenAICompatibleDriver(apiKey: '', model: 'o1-mini', baseUrl: '', httpClient: new MockHttpClient(), supportsImageInput: null))->supportsImageInput())->toBeFalse();
    expect((new OpenAICompatibleDriver(apiKey: '', model: 'gpt-4-turbo', baseUrl: '', httpClient: new MockHttpClient(), supportsImageInput: null))->supportsImageInput())->toBeTrue();
});

test('Anthropic driver respects toggle override', function (): void {
    $sonnet = new AnthropicCompatibleDriver(
        apiKey: '',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: '',
        httpClient: new MockHttpClient(),
        supportsImageInput: false,
    );
    expect($sonnet->supportsImageInput())->toBeFalse();

    $legacy = new AnthropicCompatibleDriver(
        apiKey: '',
        model: 'claude-2.0',
        baseUrl: '',
        httpClient: new MockHttpClient(),
        supportsImageInput: true,
    );
    expect($legacy->supportsImageInput())->toBeTrue();
});

test('Anthropic driver falls back to model heuristic when toggle is null', function (): void {
    expect((new AnthropicCompatibleDriver(apiKey: '', model: 'claude-3-5-sonnet-20241022', baseUrl: '', httpClient: new MockHttpClient(), supportsImageInput: null))->supportsImageInput())->toBeTrue();
    expect((new AnthropicCompatibleDriver(apiKey: '', model: 'claude-2.0', baseUrl: '', httpClient: new MockHttpClient(), supportsImageInput: null))->supportsImageInput())->toBeFalse();
    expect((new AnthropicCompatibleDriver(apiKey: '', model: 'claude-4-opus', baseUrl: '', httpClient: new MockHttpClient(), supportsImageInput: null))->supportsImageInput())->toBeTrue();
});
