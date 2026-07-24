<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Asserts Usage::fromProviderUsage captures OpenAI cache + reasoning
 * counters (cached_tokens, reasoning_tokens).
 */
test('OpenAI usage captures cached_tokens and reasoning_tokens', function (): void {
    $payload = json_encode([
        'id' => 'chatcmpl-cache',
        'choices' => [
            [
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'cached answer'],
            ],
        ],
        'usage' => [
            'prompt_tokens' => 1500,
            'completion_tokens' => 80,
            'prompt_tokens_details' => ['cached_tokens' => 1024],
            'completion_tokens_details' => ['reasoning_tokens' => 80],
        ],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
    );

    $response = $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($response->usage->provider)->toBe('openai')
        ->and($response->usage->inputTokens)->toBe(1500)
        ->and($response->usage->outputTokens)->toBe(80)
        ->and($response->usage->cachedTokens)->toBe(1024)
        ->and($response->usage->reasoningTokens)->toBe(80)
        ->and($response->usage->cacheCreationTokens)->toBe(0)
        ->and($response->usage->cacheReadTokens)->toBe(0);
});
