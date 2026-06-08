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
        'user_id'           => null,
        'name'              => 'Test Global Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Spora\Models\Agent::create([
        'user_id'              => $userId,
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
        'user_id'     => Spora\Models\Agent::find($agentId)->user_id,
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
        expect($messages[0])->toMatchArray(['role' => 'summary', 'content' => 'Compacted first two turns.']);
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
            'task_id'      => $task->id,
            'sequence'     => 1,
            'role'         => 'tool',
            'content'      => 'tool output content',
            'tool_call_id' => 'call_xyz',
            'tool_name'    => 'stub_input',
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(2);
        expect($messages[1])->toMatchArray([
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
