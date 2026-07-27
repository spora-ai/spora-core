<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use ReflectionMethod;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\ToolCall;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
    $ref = new ReflectionMethod($driver, 'buildMessages');
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
    $ref = new ReflectionMethod($driver, 'buildMessages');
    $messages = $ref->invoke($driver, $request);
    $parts = $messages[1]['content'];
    expect($parts[0])->toBe(['type' => 'text', 'text' => 'describe']);
    expect($parts[1]['type'])->toBe('image_url');
    expect($parts[1]['image_url']['url'])->toBe('data:image/png;base64,AAAA');
});

test('null content on a tool_calls response is preserved', function (): void {
    $driver = makeOpenAIRequestDriver('gpt-4o');
    $ref = new ReflectionMethod($driver, 'buildToolCallsResponse');
    $response = $ref->invoke($driver, [
        'id' => 'chatcmpl-1',
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 0],
    ], [
        'content' => null,
        'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'noop', 'arguments' => '{}']],
        ],
    ], ['contentBlocks' => [], 'textContent' => '']);
    expect($response->content)->toBeNull();
    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolCalls[0])->toBeInstanceOf(ToolCall::class);
});

test('parsed contentBlocks and textContent flow through buildToolCallsResponse', function (): void {
    // Regression for the previous "Thinking-Tag" path: the driver used to
    // surface a `displayReasoning` string that callers carried alongside
    // contentBlocks. The displayReasoning round-trip was removed; this
    // test now asserts that the parsed `contentBlocks` and `textContent`
    // (the only shapes buildToolCallsResponse reads) flow into the
    // resulting LLMResponse unchanged.
    $driver = makeOpenAIRequestDriver('gpt-4o');
    $ref = new ReflectionMethod($driver, 'buildToolCallsResponse');
    $response = $ref->invoke($driver, [
        'id' => 'chatcmpl-2',
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ], [
        'content' => 'I should look this up.',
        'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']],
        ],
    ], ['contentBlocks' => [], 'textContent' => 'I should look this up.']);
    expect($response->content)->toBe('I should look this up.');
    expect($response->toolCalls)->toHaveCount(1);
    expect($response->contentBlocks)->toBe([]);
});

/**
 * OpenAI compatible driver — reasoning surfacing.
 *
 * The driver emits reasoning sourced from `message.reasoning_content`
 * (o1/DeepSeek/MiniMax-M3), `message.reasoning`, and inline reasoning
 * tags inside `message.content`, preserving the order of the inline
 * content parts. Reasoning lands in `contentBlocks[]` as a
 * `ContentBlock::TYPE_THINKING` entry with an empty signature.
 */
test('parseResponse surfaces reasoning_content as an unsigned thinking block', function (): void {
    $payload = json_encode([
        'id' => 'chatcmpl-o1',
        'choices' => [
            [
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Final answer.',
                    'reasoning_content' => "Plan: outline, then answer.\nStep 1: derive.",
                ],
            ],
        ],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 12],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );

    $response = $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($response->content)->toBe('Final answer.')
        ->and($response->contentBlocks)->toHaveCount(2);

    $thinking = $response->contentBlocks[0];
    expect($thinking)->toBeInstanceOf(ContentBlock::class)
        ->and($thinking->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($thinking->text)->toBe("Plan: outline, then answer.\nStep 1: derive.")
        ->and($thinking->signature)->toBe('');

    $text = $response->contentBlocks[1];
    expect($text->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($text->text)->toBe('Final answer.');
});

test('parseResponse falls back to message.reasoning when reasoning_content is absent', function (): void {
    $payload = json_encode([
        'id' => 'chatcmpl-deepseek',
        'choices' => [
            [
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'short answer',
                    'reasoning' => 'chain of thought',
                ],
            ],
        ],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );

    $response = $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    $thinking = $response->contentBlocks[0];
    expect($thinking->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($thinking->text)->toBe('chain of thought')
        ->and($thinking->signature)->toBe('');
});

test('parseResponse extracts inline reasoning tags from message.content when no structured field is set', function (): void {
    $tag_open = '<' . 'think' . '>';
    $tag_close = '<' . '/' . 'think' . '>';
    $payload = json_encode([
        'id' => 'chatcmpl-distilled',
        'choices' => [
            [
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => "Visible answer. {$tag_open}internal reasoning step{$tag_close} trailing text.",
                ],
            ],
        ],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );

    $response = $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($response->contentBlocks)->toHaveCount(2);
    expect($response->contentBlocks[0]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($response->contentBlocks[0]->text)->toBe('internal reasoning step')
        ->and($response->contentBlocks[0]->signature)->toBe('')
        ->and($response->contentBlocks[1]->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($response->contentBlocks[1]->text)->toBe('Visible answer. trailing text.')
        ->and($response->content)->toBe('Visible answer. trailing text.');
});

test('parseResponse propagates reasoning_content on tool_calls responses', function (): void {
    // Regression guard for the PR #161 scenario: o-series models emit
    // `reasoning_content` on the same turn that asks for a tool call.
    // The driver must persist that chain-of-thought alongside the
    // tool-call request, otherwise reasoning is silently dropped the
    // moment a step ends with a tool call.
    $payload = json_encode([
        'id' => 'chatcmpl-o1-tool',
        'choices' => [
            [
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'reasoning_content' => 'Plan: query the knowledge base first.',
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'lookup', 'arguments' => '{}'],
                        ],
                    ],
                ],
            ],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );

    $response = $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($response->content)->toBeNull()
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->contentBlocks)->toHaveCount(1);

    $thinking = $response->contentBlocks[0];
    expect($thinking->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($thinking->text)->toBe('Plan: query the knowledge base first.')
        ->and($thinking->signature)->toBe('');
});

test('parseResponse emits no thinking block when no reasoning field and no inline tags are present', function (): void {
    // Regression guard for the no-reasoning path: the LLMContentParser text
    // block still lands in contentBlocks so $response->content round-trips,
    // but there must be no `type === thinking` entry. Otherwise the
    // frontend's `reasoningForEntry` would render a foldout for a plain
    // response.
    $payload = json_encode([
        'id' => 'chatcmpl-plain',
        'choices' => [
            [
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'just an answer'],
            ],
        ],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 4],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );

    $response = $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($response->content)->toBe('just an answer');

    $thinkingBlocks = array_filter(
        $response->contentBlocks,
        static fn(ContentBlock $b): bool => $b->type === ContentBlock::TYPE_THINKING,
    );
    expect($thinkingBlocks)->toBe([]);
});
