<?php

declare(strict_types=1);

use Spora\Agents\MessageHistoryBuilder;
use Spora\Models\TaskHistory;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * Create an empty Task row owned by a freshly-seeded agent so the builder
 * has a `task_id` to query against. The agent's user_id is not exercised
 * by MessageHistoryBuilder — it only reads TaskHistory rows.
 *
 * @return array{0: int, 1: int}  [$agentId, $userId]
 */
function seedHistoryAgent(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('hist@example.com', TEST_PASSWORD, 'Hist');

    $config = Spora\Models\LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'              => 'Test Global Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Spora\Models\Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'History Builder Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    return [$agent->id, $userId];
}

function makeHistoryTask(int $agentId): Spora\Models\Task
{
    return Spora\Models\Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Spora\Models\Agent::find($agentId)->principal_id,
        'trigger_user_id' => Spora\Models\Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'history builder test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
}

describe('MessageHistoryBuilder', function (): void {
    it('returns an empty list when no history rows exist for the task', function (): void {
        [$agentId] = seedHistoryAgent();
        $task      = makeHistoryTask($agentId);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toBe([]);
    });

    it('drops rows whose sequence falls inside a summary range and keeps the summary row', function (): void {
        [$agentId] = seedHistoryAgent();
        $task      = makeHistoryTask($agentId);

        // Pre-summary: sequences 0-2 (will be absorbed)
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Q1']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'A1']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'Q2']);
        // Summary at sequence 3 covering 0-2
        TaskHistory::create([
            'task_id'                   => $task->id,
            'sequence'                  => 3,
            'role'                      => 'summary',
            'content'                   => 'Compacted first two turns.',
            'summarized_sequence_range' => '0-2',
        ]);
        // Post-summary: sequence 4
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Q3']);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(2);
        expect($messages[0])->toMatchArray(['role' => 'user', 'content' => 'Compacted first two turns.']);
        expect($messages[1])->toMatchArray(['role' => 'user', 'content' => 'Q3']);
    });

    it('rewrites empty tool-call arguments on assistant rows to the literal "{}" string', function (): void {
        [$agentId] = seedHistoryAgent();
        $task      = makeHistoryTask($agentId);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        TaskHistory::create([
            'task_id'           => $task->id,
            'sequence'          => 1,
            'role'              => 'assistant',
            'content'           => null,
            'tool_call_payload' => json_encode([
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'stub_input', 'arguments' => []]],
            ]),
        ]);
        TaskHistory::create([
            'task_id'      => $task->id,
            'sequence'     => 2,
            'role'         => 'tool',
            'tool_call_id' => 'call_1',
            'tool_name'    => 'stub_input',
            'content'      => 'done',
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages[1]['role'])->toBe('assistant');
        expect($messages[1]['tool_calls'][0]['function']['arguments'])->toBe('{}');
    });

    it('preserves non-empty tool-call arguments unchanged', function (): void {
        [$agentId] = seedHistoryAgent();
        $task      = makeHistoryTask($agentId);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        $originalArgs = ['recipient' => 'a@b.com', 'subject' => 'Hello'];
        TaskHistory::create([
            'task_id'           => $task->id,
            'sequence'          => 1,
            'role'              => 'assistant',
            'content'           => null,
            'tool_call_payload' => json_encode([
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'send_email', 'arguments' => $originalArgs]],
            ]),
        ]);
        TaskHistory::create([
            'task_id'      => $task->id,
            'sequence'     => 2,
            'role'         => 'tool',
            'tool_call_id' => 'call_1',
            'tool_name'    => 'send_email',
            'content'      => 'sent',
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        $args    = $messages[1]['tool_calls'][0]['function']['arguments'];
        $decoded = is_string($args) ? json_decode($args, true) : $args;
        expect($decoded)->toBe($originalArgs);
    });

    it('emits {role: tool, tool_call_id, name, content} for tool rows', function (): void {
        [$agentId] = seedHistoryAgent();
        $task      = makeHistoryTask($agentId);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        TaskHistory::create([
            'task_id'           => $task->id,
            'sequence'          => 1,
            'role'              => 'assistant',
            'content'           => null,
            'tool_call_payload' => json_encode([
                ['id' => 'call_xyz', 'type' => 'function', 'function' => ['name' => 'stub_input', 'arguments' => []]],
            ]),
        ]);
        TaskHistory::create([
            'task_id'      => $task->id,
            'sequence'     => 2,
            'role'         => 'tool',
            'content'      => 'tool output content',
            'tool_call_id' => 'call_xyz',
            'tool_name'    => 'stub_input',
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(3);
        expect($messages[2])->toMatchArray([
            'role'         => 'tool',
            'tool_call_id' => 'call_xyz',
            'name'         => 'stub_input',
            'content'      => 'tool output content',
        ]);
    });

    it('strips the _seq scaffolding key from every emitted message', function (): void {
        [$agentId] = seedHistoryAgent();
        $task      = makeHistoryTask($agentId);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Q1']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'A1']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'Q2']);
        TaskHistory::create([
            'task_id'                   => $task->id,
            'sequence'                  => 3,
            'role'                      => 'summary',
            'content'                   => 'Compacted.',
            'summarized_sequence_range' => '0-1',
        ]);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Q3']);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        foreach ($messages as $msg) {
            expect($msg)->not->toHaveKey('_seq');
        }
    });
});

/**
 * Build the message list for a task seeded with `$rows` in order; the array
 * index is the sequence, so each dataset case reads as its conversation.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<array<string, mixed>>
 */
function buildTranscript(int $agentId, array $rows): array
{
    $task = makeHistoryTask($agentId);
    foreach ($rows as $sequence => $row) {
        TaskHistory::create(['task_id' => $task->id, 'sequence' => $sequence] + $row);
    }

    return (new MessageHistoryBuilder())->build($task->id);
}

function historyUser(string $content): array
{
    return ['role' => 'user', 'content' => $content];
}

function historyAssistant(?string $content = null): array
{
    return ['role' => 'assistant', 'content' => $content];
}

function historyToolCalls(?string $content = null, array $calls = []): array
{
    return [
        'role'              => 'assistant',
        'content'           => $content,
        'tool_call_payload' => $calls === [] ? null : json_encode($calls),
    ];
}

function historyToolResult(string $id, string $name, string $content = 'ok'): array
{
    return [
        'role'         => 'tool',
        'tool_call_id' => $id,
        'tool_name'    => $name,
        'content'      => $content,
    ];
}

function call(string $id, string $name, array|string $arguments = []): array
{
    return [
        'id'       => $id,
        'type'     => 'function',
        'function' => ['name' => $name, 'arguments' => $arguments],
    ];
}

describe('MessageHistoryBuilder tool-call pairing', function (): void {
    it('repairs the task-465 shape where a worker died before persisting a tool batch', function (): void {
        [$agentId] = seedHistoryAgent();

        // Verbatim from the task-465 export: a worker died between two
        // assistant batches, producing error 2013 on the next provider turn.
        $messages = buildTranscript($agentId, [
            historyUser('Research Deloitte 2026'),
            historyToolCalls(null, [
                call('call_a1eba934aeaa4edfb756f12a', 'read_url', ['op' => 'fetch', 'url' => 'https://www.deloitte.com/insights']),
                call('call_cae96da47c7b4f0eadb192c6', 'read_url', ['op' => 'fetch', 'url' => 'https://www2.deloitte.com/content']),
            ]),
            historyToolCalls(null, [
                call('call_function_pogxq3sr277c_1', 'tavily_search', ['query' => 'Deloitte AI']),
                call('call_function_pogxq3sr277c_2', 'tavily_search', ['query' => 'Deloitte governance']),
            ]),
            historyToolResult('call_function_pogxq3sr277c_1', 'tavily_search', 'results one'),
            historyToolResult('call_function_pogxq3sr277c_2', 'tavily_search', 'results two'),
        ]);

        expect(toolCallPairingFaults($messages))->toBe([]);

        expect($messages[1])->not->toHaveKey('tool_calls')
            ->and($messages[1]['content'])->not->toBeNull();

        expect($messages)->toHaveCount(6)
            ->and($messages[2]['role'])->toBe('user')
            ->and(substr_count((string) $messages[2]['content'], '[tool:read_url]'))->toBe(2)
            ->and($messages[2]['content'])->toContain('deloitte.com/insights');

        expect($messages[3]['tool_calls'][0]['id'])->toBe('call_function_pogxq3sr277c_1')
            ->and($messages[3]['tool_calls'][1]['id'])->toBe('call_function_pogxq3sr277c_2')
            ->and($messages[4])->toBe(['role' => 'tool', 'tool_call_id' => 'call_function_pogxq3sr277c_1', 'name' => 'tavily_search', 'content' => 'results one'])
            ->and($messages[5])->toBe(['role' => 'tool', 'tool_call_id' => 'call_function_pogxq3sr277c_2', 'name' => 'tavily_search', 'content' => 'results two']);
    });

    it('leaves an emptied assistant turn valid for the provider', function (): void {
        [$agentId] = seedHistoryAgent();

        // Stripping `tool_calls` from a pure tool-call turn used to leave
        // {role: assistant, content: null}, which the Chat Completions
        // contract rejects because `content` is required when `tool_calls`
        // is absent — trading error 2013 for a different 400.
        $messages = buildTranscript($agentId, [
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
        ]);

        expect($messages[1]['role'])->toBe('assistant')
            ->and($messages[1])->not->toHaveKey('tool_calls')
            ->and($messages[1]['content'])->toBeString()
            ->and($messages[1]['content'])->not->toBe('');
    });

    it('keeps real assistant text when a batch is partially stripped', function (): void {
        [$agentId] = seedHistoryAgent();

        $messages = buildTranscript($agentId, [
            historyUser('Go'),
            historyToolCalls('Let me fetch both.', [call('A', 'read_url'), call('B', 'read_url')]),
            historyToolResult('A', 'read_url'),
        ]);

        // One answered (A), one dropped (B): the assistant keeps its prose
        // and advertises only the call that actually has a result.
        expect($messages[1]['content'])->toBe('Let me fetch both.')
            ->and($messages[1]['tool_calls'])->toHaveCount(1)
            ->and($messages[1]['tool_calls'][0]['id'])->toBe('A')
            ->and(toolCallPairingFaults($messages))->toBe([]);
    });

    it('emits one user row per repaired batch, never two in a row', function (): void {
        [$agentId] = seedHistoryAgent();

        $messages = buildTranscript($agentId, [
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url'), call('B', 'read_url'), call('C', 'read_url')]),
        ]);

        expect(array_column($messages, 'role'))->toBe(['user', 'assistant', 'user'])
            ->and(substr_count((string) $messages[2]['content'], 'did not return a result'))->toBe(3);
    });

    it('drops non-array tool_call entries rather than forwarding them to the wire', function (): void {
        [$agentId] = seedHistoryAgent();

        // Non-array entries are neither answerable nor valid on the wire.
        $messages = buildTranscript($agentId, [
            historyUser('Go'),
            ['role' => 'assistant', 'content' => 'hmm', 'tool_call_payload' => '[1, 2]'],
        ]);

        foreach ($messages as $msg) {
            expect($msg)->not->toHaveKey('tool_calls');
        }
    });

    it('satisfies the pairing invariant for adversarial transcript shapes', function (array $rows): void {
        [$agentId] = seedHistoryAgent();

        expect(toolCallPairingFaults(buildTranscript($agentId, $rows)))->toBe([]);
    })->with([
        'clean single call' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyToolResult('A', 'read_url'),
        ]],

        'clean parallel calls' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url'), call('B', 'tavily_search')]),
            historyToolResult('A', 'read_url'),
            historyToolResult('B', 'tavily_search'),
        ]],

        'parallel batch with one answer missing' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url'), call('B', 'read_url')]),
            historyToolResult('A', 'read_url'),
        ]],

        'consecutive assistant batches' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyToolCalls(null, [call('B', 'tavily_search'), call('C', 'tavily_search')]),
            historyToolResult('B', 'tavily_search'),
            historyToolResult('C', 'tavily_search'),
        ]],

        'tool result preceding its assistant' => [[
            historyUser('Go'),
            historyToolResult('A', 'read_url'),
            historyToolCalls(null, [call('A', 'read_url')]),
        ]],

        'orphan tool result with no assistant anywhere' => [[
            historyUser('Go'),
            historyToolResult('A', 'read_url'),
        ]],

        'duplicate tool result' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyToolResult('A', 'read_url'),
            historyToolResult('A', 'read_url'),
        ]],

        'dangling batch at end of history' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
        ]],

        'dangling batch interrupted by a user message' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyUser('Are you there?'),
        ]],

        'dangling batch interrupted by an attachment-derived user message' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            ['role' => 'attachment', 'content' => 'the attached file'],
            historyUser('Summarise it'),
        ]],

        'dangling batch interrupted by a compaction row' => [[
            ['role' => 'summary', 'content' => 'Earlier turns.', 'summarized_sequence_range' => '0-0'],
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyUser('Continue'),
        ]],

        'three consecutive dangling assistant batches' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyToolCalls(null, [call('B', 'read_url')]),
            historyToolCalls(null, [call('C', 'read_url')]),
        ]],

        'undecodable tool_call_payload is not treated as a batch' => [[
            historyUser('Go'),
            ['role' => 'assistant', 'content' => 'ok', 'tool_call_payload' => 'not-json'],
            historyToolResult('A', 'read_url'),
        ]],

        'assistant text between a batch and its results' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyAssistant('one moment'),
            historyToolResult('A', 'read_url'),
        ]],

        'partially answered batch followed by a complete one' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url'), call('B', 'read_url')]),
            historyToolResult('A', 'read_url'),
            historyToolCalls(null, [call('C', 'tavily_search')]),
            historyToolResult('C', 'tavily_search'),
        ]],

        'tool result split from its assistant by a compaction row' => [[
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            ['role' => 'summary', 'content' => 'Compacted.', 'summarized_sequence_range' => '1-1'],
            historyToolResult('A', 'read_url'),
        ]],
    ]);

    it('leaves a well-formed transcript byte-identical', function (): void {
        [$agentId] = seedHistoryAgent();

        $rows = [
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url'), call('B', 'tavily_search')]),
            historyToolResult('A', 'read_url', 'page one'),
            historyToolResult('B', 'tavily_search', 'page two'),
            historyAssistant('All done.'),
        ];

        expect(buildTranscript($agentId, $rows))->toEqual([
            ['role' => 'user', 'content' => 'Go'],
            [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => [
                    ['id' => 'A', 'type' => 'function', 'function' => ['name' => 'read_url', 'arguments' => '{}']],
                    ['id' => 'B', 'type' => 'function', 'function' => ['name' => 'tavily_search', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'A', 'name' => 'read_url', 'content' => 'page one'],
            ['role' => 'tool', 'tool_call_id' => 'B', 'name' => 'tavily_search', 'content' => 'page two'],
            ['role' => 'assistant', 'content' => 'All done.'],
        ]);
    });

    it('is deterministic across repeated builds of the same transcript', function (): void {
        [$agentId] = seedHistoryAgent();

        $rows = [
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url'), call('B', 'read_url')]),
            historyToolCalls(null, [call('C', 'tavily_search')]),
            historyToolResult('C', 'tavily_search'),
        ];

        expect(buildTranscript($agentId, $rows))
            ->toEqual(buildTranscript($agentId, $rows));
    });

    it('truncates oversized arguments in the repair marker so it cannot flood the context window', function (): void {
        [$agentId] = seedHistoryAgent();

        $messages = buildTranscript($agentId, [
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url', ['url' => str_repeat('https://example.test/', 200)])]),
        ]);

        expect(toolCallPairingFaults($messages))->toBe([]);
        expect(strlen((string) $messages[2]['content']))->toBeLessThan(400);
    });

    it('never leaks scaffolding keys from the repair step', function (): void {
        [$agentId] = seedHistoryAgent();

        $messages = buildTranscript($agentId, [
            historyUser('Go'),
            historyToolCalls(null, [call('A', 'read_url')]),
            historyUser('Continue'),
        ]);

        foreach ($messages as $msg) {
            expect($msg)->not->toHaveKey('_seq')
                ->and($msg)->not->toHaveKey('_compaction');
        }
    });
});

describe('MessageHistoryBuilder compaction row role', function (): void {
    it('emits compaction rows as role:user because no provider accepts role:summary', function (): void {
        [$agentId] = seedHistoryAgent();

        $messages = buildTranscript($agentId, [
            historyUser('Q1'),
            historyAssistant('A1'),
            ['role' => 'summary', 'content' => 'Compacted first two turns.', 'summarized_sequence_range' => '0-1'],
            historyUser('Q3'),
        ]);

        expect($messages)->toHaveCount(2)
            ->and($messages[0]['role'])->toBe('user')
            ->and($messages[0]['content'])->toBe('Compacted first two turns.')
            ->and($messages[1])->toBe(['role' => 'user', 'content' => 'Q3']);
    });

    it('preserves every compaction row across a second compaction round', function (): void {
        [$agentId] = seedHistoryAgent();

        // Exempt from eviction via the `_compaction` sentinel, not by role.
        $messages = buildTranscript($agentId, [
            historyUser('First'),
            ['role' => 'summary', 'content' => 'First summary', 'summarized_sequence_range' => '0-0'],
            historyUser('Second'),
            ['role' => 'summary', 'content' => 'Second summary', 'summarized_sequence_range' => '2-2'],
            historyUser('Recent'),
        ]);

        expect($messages)->toHaveCount(3)
            ->and($messages[0]['role'])->toBe('user')
            ->and($messages[0]['content'])->toBe('First summary')
            ->and($messages[1]['role'])->toBe('user')
            ->and($messages[1]['content'])->toBe('Second summary')
            ->and($messages[2])->toBe(['role' => 'user', 'content' => 'Recent']);
    });

    it('keeps a compaction row alive when a later range covers its sequence', function (): void {
        [$agentId] = seedHistoryAgent();

        // Range 0-3 covers the summary's own row, yet it survives eviction.
        $messages = buildTranscript($agentId, [
            historyUser('First'),
            historyUser('Second'),
            ['role' => 'summary', 'content' => 'Old summary', 'summarized_sequence_range' => '0-1'],
            ['role' => 'summary', 'content' => 'New summary', 'summarized_sequence_range' => '0-3'],
            historyUser('Recent'),
        ]);

        expect($messages)->toHaveCount(3)
            ->and($messages[0]['content'])->toBe('Old summary')
            ->and($messages[1]['content'])->toBe('New summary')
            ->and($messages[2]['content'])->toBe('Recent');
    });
});
