<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\AnthropicCompatibleDriver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Asserts Usage::fromProviderUsage captures Anthropic cache counters
 * correctly (cache_creation_input_tokens / cache_read_input_tokens).
 */
test('Anthropic usage captures cache_creation and cache_read counters', function (): void {
    $payload = json_encode([
        'id' => 'msg_cache',
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => 'cached answer']],
        'usage' => [
            'input_tokens' => 200,
            'output_tokens' => 50,
            'cache_creation_input_tokens' => 512,
            'cache_read_input_tokens' => 1024,
            'service_tier' => 'priority',
        ],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
    );

    $response = $driver->complete(new \Spora\Drivers\ValueObjects\LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($response->usage->provider)->toBe('anthropic')
        ->and($response->usage->inputTokens)->toBe(200)
        ->and($response->usage->outputTokens)->toBe(50)
        ->and($response->usage->cacheCreationTokens)->toBe(512)
        ->and($response->usage->cacheReadTokens)->toBe(1024)
        ->and($response->usage->reasoningTokens)->toBe(0)
        ->and($response->usage->cachedTokens)->toBe(0)
        ->and($response->usage->driverMetaInfo)->toBe(['service_tier' => 'priority'])
        ->and($response->usage->rawUsage)->toBe([
            'input_tokens' => 200,
            'output_tokens' => 50,
            'cache_creation_input_tokens' => 512,
            'cache_read_input_tokens' => 1024,
            'service_tier' => 'priority',
        ]);
});
