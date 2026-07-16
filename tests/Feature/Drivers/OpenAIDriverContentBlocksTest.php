<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\ToolCall;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Plan §12 B2b — OpenAI driver content-block wire shape.
 */
function makeOpenAIRequestDriver(string $model): OpenAICompatibleDriver
{
    return new OpenAICompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );
}

function makeOpenAIRequestWithBlocks(array $blocks): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are a helpful assistant.',
        messages: [
            ['role' => 'user', 'content' => $blocks],
        ],
        tools: [],
        maxTokens: 1024,
        temperature: 0.7,
    );
}

test('text blocks render as {type:text, text}', function (): void {
    $driver = makeOpenAIRequestDriver('gpt-4o');
    $request = makeOpenAIRequestWithBlocks([
        ['type' => 'text', 'text' => 'describe this'],
    ]);
    // Use reflection to invoke the private buildMessages via complete();
    // since complete() requires a real HTTP response, we inspect via the
    // protected path instead.
    $ref = new \ReflectionMethod($driver, 'buildMessages');
    $ref->setAccessible(true);
    $messages = $ref->invoke($driver, $request);
    expect($messages[1]['content'])->toBeArray();
    expect($messages[1]['content'][0])->toBe(['type' => 'text', 'text' => 'describe this']);
});

test('image blocks render as {type:image_url, image_url:{url:data:...}}', function (): void {
    $driver = makeOpenAIRequestDriver('gpt-4o');
    $request = makeOpenAIRequestWithBlocks([
        ['type' => 'text', 'text' => 'describe'],
        ['type' => 'image', 'mediaType' => 'image/png', 'base64' => 'AAAA'],
    ]);
    $ref = new \ReflectionMethod($driver, 'buildMessages');
    $ref->setAccessible(true);
    $messages = $ref->invoke($driver, $request);
    $parts = $messages[1]['content'];
    expect($parts[0])->toBe(['type' => 'text', 'text' => 'describe']);
    expect($parts[1]['type'])->toBe('image_url');
    expect($parts[1]['image_url']['url'])->toBe('data:image/png;base64,AAAA');
});

test('null content on a tool_calls response is preserved', function (): void {
    $driver = makeOpenAIRequestDriver('gpt-4o');
    $ref = new \ReflectionMethod($driver, 'buildToolCallsResponse');
    $ref->setAccessible(true);
    $response = $ref->invoke($driver, [
        'id' => 'chatcmpl-1',
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 0],
    ], [
        'content' => null,
        'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'noop', 'arguments' => '{}']],
        ],
    ], ['content' => null, 'reasoning' => null]);
    expect($response->content)->toBeNull();
    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolCalls[0])->toBeInstanceOf(ToolCall::class);
});