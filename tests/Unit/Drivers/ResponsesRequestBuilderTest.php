<?php

declare(strict_types=1);

use Spora\Drivers\Responses\ResponsesRequestBuilder;
use Spora\Drivers\ValueObjects\LLMRequest;

/**
 * The Responses wire shape is not a dialect of Chat Completions, so each
 * conversion is pinned on its own: the system prompt moves to the top
 * level, the tools are internally tagged, a tool result becomes an
 * input item, and a reasoning item is a sibling of its message.
 */
function responsesBuilder(bool $store = false, string $model = 'gpt-6-astra'): ResponsesRequestBuilder
{
    return new ResponsesRequestBuilder('sk-test', $model, $store);
}

function responsesRequest(array $messages = [], array $tools = [], string $systemPrompt = 'You are helpful.'): LLMRequest
{
    return new LLMRequest(
        systemPrompt: $systemPrompt,
        messages: $messages,
        tools: $tools,
    );
}

test('the system prompt is sent as the top-level instructions field', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(systemPrompt: 'Be terse.'));

    expect($body)->toHaveKey('instructions')
        ->and($body['instructions'])->toBe('Be terse.');
});

test('the body carries no flat reasoning_effort and no max_tokens', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(), 'high');

    expect($body)->not->toHaveKey('reasoning_effort')
        ->and($body)->not->toHaveKey('max_tokens')
        ->and($body)->toHaveKey('max_output_tokens');
});

test('tool definitions are internally tagged and carry an explicit strict flag', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(tools: [[
        'type' => 'function',
        'function' => [
            'name' => 'send_email',
            'description' => 'Sends an email.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['to' => ['type' => 'string']],
                'required' => ['to'],
            ],
        ],
    ]]));

    expect($body['tools'])->toHaveCount(1);
    expect($body['tools'][0])->toBe([
        'type' => 'function',
        'name' => 'send_email',
        'description' => 'Sends an email.',
        'parameters' => [
            'type' => 'object',
            'properties' => ['to' => ['type' => 'string']],
            'required' => ['to'],
        ],
        // Spora's schemas do not close their objects, so the strict mode
        // the API would attempt would fall back silently.
        'strict' => false,
    ]);
});

test('a schema that already satisfies the strict rules is sent as strict', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(tools: [[
        'type' => 'function',
        'function' => [
            'name' => 'strict_tool',
            'description' => 'Already strict-compatible.',
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['to' => ['type' => 'string']],
                'required' => ['to'],
            ],
        ],
    ]]));

    expect($body['tools'][0]['strict'])->toBeTrue();
});

test('a schema that leaves a property out of required is not strict', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(tools: [[
        'type' => 'function',
        'function' => [
            'name' => 'half_strict_tool',
            'description' => 'One optional property.',
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'to' => ['type' => 'string'],
                    'cc' => ['type' => 'string'],
                ],
                'required' => ['to'],
            ],
        ],
    ]]));

    expect($body['tools'][0]['strict'])->toBeFalse();
});

test('a tool without a parameter schema sends an empty object, not an empty array', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(tools: [[
        'type' => 'function',
        'function' => ['name' => 'no_params_tool', 'description' => 'Takes nothing.'],
    ]]));

    expect(json_encode($body['tools'][0]['parameters']))->toBe('{}');
});

test('an assistant turn with tool calls becomes a message and one function_call item per call', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [[
        'role' => 'assistant',
        'content' => 'Calling two tools.',
        'tool_calls' => [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'tool_a', 'arguments' => '{"x":1}']],
            ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'tool_b', 'arguments' => '{}']],
        ],
    ]]));

    expect($body['input'])->toHaveCount(3);

    expect($body['input'][0])->toBe([
        'type' => 'message',
        'role' => 'assistant',
        'content' => [['type' => 'output_text', 'text' => 'Calling two tools.']],
    ]);

    expect($body['input'][1])->toBe([
        'type' => 'function_call',
        'call_id' => 'call_a',
        'name' => 'tool_a',
        'arguments' => '{"x":1}',
    ]);

    expect($body['input'][2])->toBe([
        'type' => 'function_call',
        'call_id' => 'call_b',
        'name' => 'tool_b',
        'arguments' => '{}',
    ]);
});

test('a tool row becomes a function_call_output item keyed by call_id', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [[
        'role' => 'tool',
        'tool_call_id' => 'call_a',
        'name' => 'tool_a',
        'content' => 'the result',
    ]]));

    expect($body['input'])->toBe([
        [
            'type' => 'function_call_output',
            'call_id' => 'call_a',
            'output' => 'the result',
        ],
    ]);
});

test('a reasoning block is replayed in position, before the message it belongs to', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [[
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'reasoning',
                'text' => 'Weighing the options.',
                'data' => 'encrypted-blob',
                'metadata' => [
                    'id' => 'rs_123',
                    'summary' => [['type' => 'summary_text', 'text' => 'Weighing the options.']],
                ],
            ],
            ['type' => 'text', 'text' => 'The answer is 42.'],
        ],
        'tool_calls' => [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'tool_a', 'arguments' => '{}']],
        ],
    ]]));

    expect($body['input'])->toHaveCount(3);

    expect($body['input'][0])->toBe([
        'type' => 'reasoning',
        'id' => 'rs_123',
        'summary' => [['type' => 'summary_text', 'text' => 'Weighing the options.']],
        'encrypted_content' => 'encrypted-blob',
    ]);

    expect($body['input'][1])->toBe([
        'type' => 'message',
        'role' => 'assistant',
        'content' => [['type' => 'output_text', 'text' => 'The answer is 42.']],
    ]);

    expect($body['input'][2]['type'])->toBe('function_call');
});

test('a reasoning item is replayed with its blob when the response is stored', function (): void {
    $body = responsesBuilder(store: true)->buildBody(responsesRequest(messages: [[
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'reasoning',
                'text' => 'Weighing the options.',
                'data' => 'encrypted-blob',
                'metadata' => ['id' => 'rs_123', 'summary' => []],
            ],
        ],
    ]]));

    expect($body['input'][0])->toBe([
        'type' => 'reasoning',
        'id' => 'rs_123',
        'summary' => [],
        'encrypted_content' => 'encrypted-blob',
    ]);
});

test('a reasoning item without a blob is replayed by id when the response is stored', function (): void {
    $body = responsesBuilder(store: true)->buildBody(responsesRequest(messages: [[
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'reasoning',
                'text' => 'Weighing the options.',
                'metadata' => ['id' => 'rs_123', 'summary' => []],
            ],
        ],
    ]]));

    expect($body['input'][0])->toBe([
        'type' => 'reasoning',
        'id' => 'rs_123',
        'summary' => [],
    ]);
});

test('a reasoning item without a blob is skipped when the response is not stored', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [[
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'reasoning',
                'text' => 'Weighing the options.',
                'metadata' => ['id' => 'rs_123', 'summary' => []],
            ],
            ['type' => 'text', 'text' => 'The answer is 42.'],
        ],
    ]]));

    expect($body['input'])->toHaveCount(1);
    expect($body['input'][0]['type'])->toBe('message');
    expect(json_encode($body['input']))->not->toContain('encrypted_content');
});

test('a reasoning item is never sent with an empty encrypted content', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [[
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'reasoning',
                'text' => 'Weighing the options.',
                'data' => '',
                'metadata' => ['id' => 'rs_123', 'summary' => []],
            ],
        ],
    ]]));

    expect(json_encode($body['input']))->not->toContain('encrypted_content');
});

test('the encrypted reasoning content is requested from a reasoning model', function (): void {
    $body = responsesBuilder(model: 'gpt-6-astra')->buildBody(responsesRequest());

    expect($body['include'])->toBe(['reasoning.encrypted_content']);
});

test('a model that does not reason asks for no encrypted reasoning content', function (): void {
    $body = responsesBuilder(model: 'gpt-4o')->buildBody(responsesRequest());

    expect($body)->not->toHaveKey('include');
});

test('a system row stays an input item and is not folded into the instructions', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(
        messages: [['role' => 'system', 'content' => '{"kind":"abort_marker"}']],
        systemPrompt: 'The recipe system prompt.',
    ));

    expect($body['instructions'])->toBe('The recipe system prompt.');
    expect($body['input'])->toBe([
        [
            'type' => 'message',
            'role' => 'system',
            'content' => [['type' => 'input_text', 'text' => '{"kind":"abort_marker"}']],
        ],
    ]);
});

test('an image block becomes an input_image part with the url as a plain string', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image', 'mediaType' => 'image/png', 'base64' => 'AAAA'],
        ],
    ]]));

    expect($body['input'][0]['content'])->toBe([
        ['type' => 'input_text', 'text' => 'What is this?'],
        ['type' => 'input_image', 'image_url' => 'data:image/png;base64,AAAA', 'detail' => 'auto'],
    ]);
});

test('a user turn carries an input_text part and an assistant turn an output_text part', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Knock knock.']]],
        ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => "Who's there?"]]],
    ]));

    expect($body['input'][0]['content'])->toBe([['type' => 'input_text', 'text' => 'Knock knock.']]);
    expect($body['input'][1]['content'])->toBe([['type' => 'output_text', 'text' => "Who's there?"]]);
});

test('a plain string content follows the role too', function (): void {
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [
        ['role' => 'user', 'content' => 'Knock knock.'],
        ['role' => 'assistant', 'content' => "Who's there?"],
    ]));

    expect($body['input'][0]['content'])->toBe([['type' => 'input_text', 'text' => 'Knock knock.']]);
    expect($body['input'][1]['content'])->toBe([['type' => 'output_text', 'text' => "Who's there?"]]);
});

test('an assistant row carries no image part', function (): void {
    // The assistant role takes `output_text` and `refusal` — there is no
    // `input_image` there.
    $body = responsesBuilder()->buildBody(responsesRequest(messages: [[
        'role' => 'assistant',
        'content' => [
            ['type' => 'text', 'text' => 'Here it is.'],
            ['type' => 'image', 'mediaType' => 'image/png', 'base64' => 'AAAA'],
        ],
    ]]));

    expect($body['input'][0]['content'])->toBe([['type' => 'output_text', 'text' => 'Here it is.']]);
});
