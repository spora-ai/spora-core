<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\ValueObjects\ContentBlock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Asserts an Anthropic response with thinking and redacted_thinking
 * blocks round-trips through the driver into structured ContentBlocks
 * with the signature byte-identical.
 */
test('Anthropic thinking and redacted_thinking blocks round-trip with signatures', function (): void {
    $payload = json_encode([
        'id' => 'msg_thinking_round_trip',
        'stop_reason' => 'end_turn',
        'content' => [
            ['type' => 'thinking', 'thinking' => 'Plan: search then summarize', 'signature' => 'sig-abc-123'],
            ['type' => 'redacted_thinking', 'data' => 'encrypted-payload-xyz'],
            ['type' => 'text', 'text' => 'Summary: the answer is 42.'],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
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

    expect($response->content)->toBe('Summary: the answer is 42.')
        ->and($response->contentBlocks)->toHaveCount(3);

    $thinking = $response->contentBlocks[0];
    expect($thinking)->toBeInstanceOf(ContentBlock::class);
    expect($thinking->type)->toBe(ContentBlock::TYPE_THINKING);
    expect($thinking->text)->toBe('Plan: search then summarize');
    expect($thinking->signature)->toBe('sig-abc-123');

    $redacted = $response->contentBlocks[1];
    expect($redacted->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING);
    expect($redacted->data)->toBe('encrypted-payload-xyz');

    $text = $response->contentBlocks[2];
    expect($text->type)->toBe(ContentBlock::TYPE_TEXT);
    expect($text->text)->toBe('Summary: the answer is 42.');
});

/**
 * Full round-trip: receive a thinking block, persist it as a `task_history`
 * row, replay it through `MessageHistoryBuilder`, and assert that the next
 * outbound Anthropic request body carries the same `signature`
 * byte-identical. A break in the chain — a stringly-typed reencode, a
 * trimmed copy, a missing field — will be caught here.
 */
test('Anthropic thinking signature is replayed byte-identical on the next outbound turn', function (): void {
    $expectedSignature = 'sig-replay-abc';

    $captured = null;
    $callIndex = 0;
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, &$callIndex, $expectedSignature): MockResponse {
        $callIndex++;
        $body = $options['body'] ?? '';
        $decoded = json_decode((string) $body, true);

        if ($callIndex === 2) {
            $captured = $decoded;
        }

        $payload = match ($callIndex) {
            1 => [
                'id' => 'msg_thinking_1',
                'stop_reason' => 'end_turn',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Plan: call a tool', 'signature' => $expectedSignature],
                    ['type' => 'text', 'text' => 'first turn'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ],
            default => [
                'id' => 'msg_thinking_2',
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'second turn']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ],
        };

        return new MockResponse(json_encode($payload), ['http_code' => 200]);
    });

    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
    );

    // First turn: receive the signed thinking block from the provider.
    $firstResponse = $driver->complete(new \Spora\Drivers\ValueObjects\LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($firstResponse->contentBlocks[0]->signature)->toBe($expectedSignature);

    // Persist the response as a `task_history` row.
    $auth = bootAuthLayer();
    $userId = $auth->register('roundtrip@example.com', 'Password1!', 'RoundTrip');
    $agent = \Spora\Models\Agent::create([
        'user_id' => $userId,
        'name' => 'RoundTripAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    $task = \Spora\Models\Task::create([
        'user_id' => $userId,
        'agent_id' => $agent->id,
        'status' => 'RUNNING',
        'user_prompt' => 'round-trip',
        'max_steps' => 5,
    ]);
    \Spora\Models\TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => 1,
        'role' => 'assistant',
        'content' => $firstResponse->content,
        'content_blocks' => array_map(
            static fn(ContentBlock $b): array => $b->toArray(),
            $firstResponse->contentBlocks,
        ),
    ]);

    // Replay the persisted row through the production builder.
    $replayed = (new \Spora\Agents\MessageHistoryBuilder())->build($task->id);

    // Second turn: feed the replayed message back into the driver.
    $driver->complete(new \Spora\Drivers\ValueObjects\LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: $replayed,
        tools: [],
    ));

    expect($captured)->not->toBeNull();

    // The assistant message must carry the same thinking block, with the
    // original signature byte-identical.
    $assistantMessage = $captured['messages'][0] ?? null;
    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage['role'])->toBe('assistant');

    $contentBlocks = $assistantMessage['content'] ?? [];
    expect($contentBlocks)->toBeArray();

    $thinkingBlock = null;
    foreach ($contentBlocks as $block) {
        if (is_array($block) && ($block['type'] ?? null) === 'thinking') {
            $thinkingBlock = $block;
            break;
        }
    }

    expect($thinkingBlock)->not->toBeNull();
    expect($thinkingBlock['signature'])->toBe($expectedSignature);
    expect($thinkingBlock['thinking'])->toBe('Plan: call a tool');
});
