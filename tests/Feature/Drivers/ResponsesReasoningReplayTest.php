<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A reasoning item is opaque: the provider decrypts `encrypted_content`
 * to restore the chain of thought. The encrypted content has to survive
 * the round trip through `task_history` byte-identical, or the chain
 * breaks on the next turn — silently, because the API accepts a
 * reasoning item without one.
 */
test('a reasoning item survives a round trip through task_history and is replayed byte-identical', function (): void {
    $expectedBlob = 'encrypted-blob-abc';

    $captured = null;
    $callIndex = 0;
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured, &$callIndex, $expectedBlob): MockResponse {
        $callIndex++;
        $body = json_decode((string) ($options['body'] ?? ''), true);

        if ($callIndex === 2) {
            $captured = $body;
        }

        $payload = match ($callIndex) {
            1 => [
                'id' => 'resp_reasoning_1',
                'status' => 'completed',
                'output' => [
                    [
                        'type' => 'reasoning',
                        'id' => 'rs_abc',
                        'summary' => [['type' => 'summary_text', 'text' => 'Weighing the options.']],
                        'encrypted_content' => $expectedBlob,
                    ],
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => 'The answer is 42.']],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ],
            default => [
                'id' => 'resp_reasoning_2',
                'status' => 'completed',
                'output' => [],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ],
        };

        return new MockResponse(json_encode($payload), ['http_code' => 200]);
    });

    $driver = new OpenAIResponsesDriver(
        apiKey: 'test',
        model: 'gpt-6-astra',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
    );

    $firstResponse = $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
    ));

    expect($firstResponse->contentBlocks[0]->type)->toBe(ContentBlock::TYPE_REASONING);
    expect($firstResponse->contentBlocks[0]->data)->toBe($expectedBlob);

    // Persist the response as a `task_history` row.
    $auth = bootAuthLayer();
    $userId = $auth->register('responses-replay@example.com', 'Password1!', 'ResponsesReplay');
    $agent = \Spora\Models\Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name' => 'ResponsesReplayAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    $task = \Spora\Models\Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id' => $agent->id,
        'status' => 'RUNNING',
        'user_prompt' => 'replay',
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
    $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: $replayed,
        tools: [],
    ));

    expect($captured)->not->toBeNull();

    $items = $captured['input'];

    // `toEqual()`, not `toBe()`: the row came back out of a native `json`
    // column, and MySQL normalises an object by sorting its keys — so the
    // key order here is the engine's, not the provider's. A JSON object has
    // no order; the content has to survive the round trip, the sequence the
    // column happens to store it in does not.
    expect($items[0])->toEqual([
        'type' => 'reasoning',
        'id' => 'rs_abc',
        'summary' => [['type' => 'summary_text', 'text' => 'Weighing the options.']],
        'encrypted_content' => $expectedBlob,
    ]);
});

test('the reasoning metadata never reaches the frontend', function (): void {
    $sanitized = \Spora\Services\TaskHistorySerializer::sanitizeContentBlocksForApi([
        [
            'type' => 'reasoning',
            'text' => 'Weighing the options.',
            'data' => 'encrypted-blob-abc',
            'metadata' => ['id' => 'rs_abc', 'summary' => []],
        ],
    ]);

    expect($sanitized[0])->not->toHaveKey('data')
        ->and($sanitized[0])->not->toHaveKey('metadata')
        ->and($sanitized[0]['text'])->toBe('Weighing the options.');
});
