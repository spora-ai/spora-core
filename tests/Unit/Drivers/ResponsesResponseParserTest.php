<?php

declare(strict_types=1);

use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Responses\ResponsesResponseParser;
use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * The Responses body is a list of output *items* of different kinds, so
 * each kind is pinned on its own — and the status is read before any of
 * them, because a 200 can carry a response that produced nothing.
 */
function responsesParser(): ResponsesResponseParser
{
    return new ResponsesResponseParser(null);
}

test('a failed status is read before the output and throws', function (): void {
    $parser = responsesParser();

    expect(fn() => $parser->parse([
        'id' => 'resp_failed',
        'status' => 'failed',
        'error' => ['code' => 'server_error', 'message' => 'the model gave up'],
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ignored']]]],
    ]))->toThrow(LLMProviderException::class, 'the model gave up');
});

test('a queued response throws instead of returning an empty answer', function (): void {
    $parser = responsesParser();

    expect(fn() => $parser->parse([
        'id' => 'resp_queued',
        'status' => 'queued',
        'output' => [],
    ]))->toThrow(LLMProviderException::class);
});

test('the output text is aggregated by hand from the output_text parts', function (): void {
    $response = responsesParser()->parse([
        'id' => 'resp_text',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Hello '],
                    ['type' => 'refusal', 'refusal' => 'no'],
                    ['type' => 'output_text', 'text' => 'world.'],
                ],
            ],
        ],
        'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
    ]);

    expect($response->content)->toBe('Hello world.')
        ->and($response->toolCalls)->toBeEmpty()
        ->and($response->completionId)->toBe('resp_text');
});

test('a function_call item becomes a tool call keyed by call_id', function (): void {
    $response = responsesParser()->parse([
        'id' => 'resp_tool',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'function_call',
                'id' => 'fc_123',
                'call_id' => 'call_abc',
                'name' => 'send_email',
                'arguments' => '{"to":"a@example.com"}',
            ],
        ],
        'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
    ]);

    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolCalls[0]->providerCallId)->toBe('call_abc');
    expect($response->toolCalls[0]->toolName)->toBe('send_email');
    expect($response->toolCalls[0]->arguments)->toBe(['to' => 'a@example.com']);
});

test('a reasoning item becomes a reasoning block carrying the encrypted content', function (): void {
    $response = responsesParser()->parse([
        'id' => 'resp_reasoning',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'reasoning',
                'id' => 'rs_123',
                'summary' => [['type' => 'summary_text', 'text' => 'Weighing the options.']],
                'encrypted_content' => 'encrypted-blob',
            ],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'The answer is 42.']],
            ],
        ],
        'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
    ]);

    expect($response->contentBlocks)->toHaveCount(2);

    $reasoning = $response->contentBlocks[0];
    expect($reasoning->type)->toBe(ContentBlock::TYPE_REASONING);
    expect($reasoning->text)->toBe('Weighing the options.');
    expect($reasoning->data)->toBe('encrypted-blob');
    expect($reasoning->metadata)->toBe([
        'id' => 'rs_123',
        'summary' => [['type' => 'summary_text', 'text' => 'Weighing the options.']],
    ]);

    // The text needs a block of its own: the history replays an
    // assistant row from its content blocks, so a reasoning block alone
    // would replace the answer.
    expect($response->contentBlocks[1]->type)->toBe(ContentBlock::TYPE_TEXT);
    expect($response->contentBlocks[1]->text)->toBe('The answer is 42.');
});

test('the tool name the provider echoes is mapped back to the name Spora knows', function (): void {
    $response = responsesParser()->parse([
        'id' => 'resp_mapped',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'function_call',
                'call_id' => 'call_abc',
                'name' => 'my-plugin-send_email',
                'arguments' => '{}',
            ],
        ],
        'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
    ], ['my-plugin-send_email' => 'my-plugin:send_email']);

    expect($response->toolCalls[0]->toolName)->toBe('my-plugin:send_email');
});
