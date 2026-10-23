<?php

declare(strict_types=1);

use Spora\Agents\ConversationCompactor;
use Spora\Agents\MessageHistoryBuilder;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * Boot an agent/user/task pair for compaction tests.
 *
 * @return array{0: Agent, 1: Task}
 */
function seedCompactionTask(string $systemPrompt = 'You are a helpful AI assistant.'): array
{
    $userId = bootAuthLayer()->register('cwr@example.com', TEST_PASSWORD, 'CWR');
    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'              => 'CWR Test Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);
    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'CWR Test Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => $systemPrompt,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'    => $agent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'compact test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    return [$agent, $task];
}

/**
 * A driver that records the request it was handed.
 *
 * @param  LLMRequest|null  $captured  Filled with the request the summariser received.
 */
function compactionDriver(?LLMRequest &$captured = null): LLMDriverInterface
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')
        ->andReturnUsing(static function (LLMRequest $req) use (&$captured): LLMResponse {
            $captured = $req;
            return new LLMResponse('compacted.', [], 10, 5, 'cmp_summary');
        });
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');

    return $driver;
}

describe('ConversationCompactor', function (): void {
    it('rephrases role:tool rows to role:user with [tool:<name>] prefix and scrubs data: URIs', function (): void {
        [$agent, $task] = seedCompactionTask();

        // Eight history rows; keepCount = 5, so sequences 0-2 are
        // summarized and 3-7 are kept. We seed sequence 0 with a tool
        // row (with a `data:` URI so we can also test scrubbing) and
        // sequence 1 with a user row that carries a `data:image/png`
        // URI. Both must be visible in the captured summary request.
        for ($i = 0; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Plain message {$i}",
            ]);
        }
        TaskHistory::where('task_id', $task->id)
            ->where('sequence', 0)
            ->update([
                'role'        => 'tool',
                'content'     => 'payload: data:image/png;base64,iVBORw0KGgo= end',
                'tool_name'   => 'image_tool',
                'tool_call_id' => 'call_abc',
            ]);
        TaskHistory::where('task_id', $task->id)
            ->where('sequence', 1)
            ->update([
                'role'    => 'user',
                'content' => 'Clean message: data:image/png;base64,iVBORw0KGgo= end',
            ]);

        $captured = null;
        $factory  = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver($captured));

        $compacted = (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        expect($compacted)->toBeTrue();
        expect($captured)->not->toBeNull();
        $messages = $captured->messages;

        // The summariser sees `role:'tool'` rephrased to `role:'user'`
        // with `[tool:<name>]` prefix and ScrubDataUrls run over the
        // content before it is sent.
        $toolRephrased   = null;
        $scrubbedMessage = null;
        foreach ($messages as $msg) {
            $role    = $msg['role'];
            $content = $msg['content'];
            if (!is_string($content)) {
                continue;
            }
            if ($role === 'user' && str_starts_with($content, '[tool:image_tool] ')) {
                $toolRephrased = $msg;
            }
            if ($role === 'user' && str_contains($content, '[data-omitted]')) {
                $scrubbedMessage = $msg;
            }
        }

        expect($toolRephrased)->not->toBeNull()
            ->and($toolRephrased['role'])->toBe('user')
            ->and($toolRephrased['content'])->toStartWith('[tool:image_tool] ')
            ->and($toolRephrased['content'])->not->toContain('data:image/png;base64');

        expect($scrubbedMessage)->not->toBeNull()
            ->and($scrubbedMessage['content'])->toContain('[data-omitted]')
            ->and($scrubbedMessage['content'])->not->toContain('data:image/png;base64');

        // A `summary` row is written that replaces the summarized range.
        $summaryRow = TaskHistory::where('task_id', $task->id)
            ->where('role', 'summary')
            ->first();
        expect($summaryRow)->not->toBeNull()
            ->and($summaryRow->content)->toBe('compacted.')
            ->and($summaryRow->summarized_sequence_range)->toBe('0-2');
    });

    it('keeps the summarized rows in the history', function (): void {
        [$agent, $task] = seedCompactionTask();

        for ($i = 0; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver());

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        // The operator can still read every turn: the rows are only
        // replaced in the model's view, not in the transcript.
        expect(TaskHistory::where('task_id', $task->id)->count())->toBe(9)
            ->and(TaskHistory::where('task_id', $task->id)->where('sequence', 0)->first()?->content)
            ->toBe('Message 0')
            ->and(TaskHistory::where('task_id', $task->id)->where('sequence', 7)->first()?->content)
            ->toBe('Message 7');
    });

    it('appends the summary row after the rows it replaces and leaves their sequences alone', function (): void {
        [$agent, $task] = seedCompactionTask();

        for ($i = 0; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver());

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        $summaryRow = TaskHistory::where('task_id', $task->id)->where('role', 'summary')->first();

        // The summary lands after the last row rather than in the middle of
        // them: an inserted row would shift every sequence after it and the
        // SPA's `since_sequence` cursor would replay them.
        expect($summaryRow)->not->toBeNull()
            ->and((int) $summaryRow->sequence)->toBe(8)
            ->and(TaskHistory::where('task_id', $task->id)->where('sequence', 5)->first()?->content)
            ->toBe('Message 5');
    });

    it('sends the summary in place of the rows it replaces', function (): void {
        [$agent, $task] = seedCompactionTask();

        for ($i = 0; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver());

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        // The model sees the summary and the five rows that follow it.
        expect($messages)->toHaveCount(6)
            ->and($messages[0]['content'])->toBe('compacted.')
            ->and(array_column($messages, 'content'))->toContain('Message 3', 'Message 7')
            ->and(array_column($messages, 'content'))->not->toContain('Message 0', 'Message 1', 'Message 2');
    });

    it('reports no compaction when the history is too short to summarize', function (): void {
        [$agent, $task] = seedCompactionTask();

        for ($i = 0; $i < ConversationCompactor::KEEP_ROWS + 1; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => 'user',
                'content'  => "Message {$i}",
            ]);
        }

        $factory = Mockery::mock(DriverFactory::class);
        $factory->shouldNotReceive('makeFromAgent');

        $compacted = (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        expect($compacted)->toBeFalse()
            ->and(TaskHistory::where('task_id', $task->id)->where('role', 'summary')->count())->toBe(0);
    });

    it('writes no summary row when the model returns no text', function (): void {
        [$agent, $task] = seedCompactionTask();

        for ($i = 0; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        // A reasoning model can spend its whole output budget thinking and
        // return no text. Writing that as the summary would replace the
        // summarized turns with nothing.
        $driver = Mockery::mock(LLMDriverInterface::class);
        $driver->allows('complete')->andReturn(new LLMResponse('', [], 10, 1024, 'cmp_summary'));
        $driver->allows('getProviderName')->andReturn('mock');
        $driver->allows('getModelName')->andReturn('mock-model');

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($driver);

        expect(fn() => (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2))
            ->toThrow(Spora\Agents\Exceptions\CompactionFailedException::class);

        expect(TaskHistory::where('task_id', $task->id)->where('role', 'summary')->count())->toBe(0)
            ->and(TaskHistory::where('task_id', $task->id)->count())->toBe(8);
    });

    it('gives the summary request room to think', function (): void {
        [$agent, $task] = seedCompactionTask();

        for ($i = 0; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $captured = null;
        $driver = Mockery::mock(LLMDriverInterface::class);
        $driver->allows('complete')->andReturnUsing(static function (LLMRequest $req) use (&$captured): LLMResponse {
            $captured = $req;
            return new LLMResponse('compacted.', [], 10, 5, 'cmp_summary');
        });
        $driver->allows('getProviderName')->andReturn('mock');
        $driver->allows('getModelName')->andReturn('mock-model');

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($driver);

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        // A cap of 1024 tokens left a reasoning model with nothing to write
        // the summary with.
        expect($captured->maxTokens)->toBeGreaterThan(1024);
    });

    it('truncates a long tool result for the summarizer', function (): void {
        [$agent, $task] = seedCompactionTask();

        TaskHistory::create([
            'task_id'      => $task->id,
            'sequence'     => 0,
            'role'         => 'tool',
            'tool_name'    => 'staan_search',
            'tool_call_id' => 'call_1',
            // Multi-byte characters on purpose: a byte-wise cut would split
            // one in half and leave the request body invalid UTF-8.
            'content'      => str_repeat('Honigbienen-Bestand äöüß ', 4000),
        ]);
        for ($i = 1; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 1 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $captured = null;
        $driver = Mockery::mock(LLMDriverInterface::class);
        $driver->allows('complete')->andReturnUsing(static function (LLMRequest $req) use (&$captured): LLMResponse {
            $captured = $req;
            return new LLMResponse('compacted.', [], 10, 5, 'cmp_summary');
        });
        $driver->allows('getProviderName')->andReturn('mock');
        $driver->allows('getModelName')->andReturn('mock-model');

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($driver);

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        // An unbounded tool dump makes the model think for tens of thousands
        // of tokens and exhaust its budget before it writes anything.
        $toolMessage = null;
        foreach ($captured->messages as $message) {
            if (str_starts_with((string) $message['content'], '[tool:staan_search]')) {
                $toolMessage = $message;
            }
        }

        expect($toolMessage)->not->toBeNull()
            ->and(mb_strlen((string) $toolMessage['content']))->toBeLessThan(2000)
            ->and((string) $toolMessage['content'])->toContain('…[truncated]')
            // The cut has to leave valid UTF-8 behind.
            ->and(mb_check_encoding((string) $toolMessage['content'], 'UTF-8'))->toBeTrue();
    });

    it('leaves the replayed history pairable when the summarised range splits an assistant/tool pair', function (): void {
        [$agent, $task] = seedCompactionTask();

        // The range boundary splits an assistant from its result, leaving a
        // bare `tool` row that providers reject with 2013.
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Q0']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'A1']);
        TaskHistory::create([
            'task_id'           => $task->id,
            'sequence'          => 2,
            'role'              => 'assistant',
            'content'           => null,
            'tool_call_payload' => json_encode([
                ['id' => 'call_split', 'type' => 'function', 'function' => ['name' => 'read_url', 'arguments' => ['url' => 'https://example.test']]],
            ]),
        ]);
        TaskHistory::create([
            'task_id'       => $task->id,
            'sequence'      => 3,
            'role'          => 'tool',
            'tool_call_id'  => 'call_split',
            'tool_name'     => 'read_url',
            'content'       => 'fetched page',
        ]);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Q4']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 5, 'role' => 'assistant', 'content' => 'A5']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 6, 'role' => 'user', 'content' => 'Q6']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 7, 'role' => 'assistant', 'content' => 'A7']);

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver());

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect(toolCallPairingFaults($messages))->toBe([]);

        // The declaration is gone, so the result becomes user text.
        $roles = array_column($messages, 'role');
        expect($roles)->not->toContain('tool')
            ->and(implode("\n", array_map(
                static fn(array $m): string => (string) ($m['content'] ?? ''),
                $messages,
            )))->toContain('read_url');
    });

    it('never sends role:summary to the summarizer on a second compaction round', function (): void {
        [$agent, $task] = seedCompactionTask();

        // Must sit at the lowest sequence to fall inside the summarized range.
        TaskHistory::create([
            'task_id'                   => $task->id,
            'sequence'                  => 0,
            'role'                      => 'summary',
            'content'                   => 'First round summary.',
            'summarized_sequence_range' => '0-0',
        ]);
        for ($i = 1; $i <= 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 1 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $captured = null;
        $factory  = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver($captured));

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        expect($captured)->not->toBeNull();
        $sent = $captured->messages;
        expect($sent)->not->toBeEmpty();

        foreach ($sent as $msg) {
            expect($msg['role'])->toBeIn(['user', 'assistant', 'system']);
        }
    });

    it('carries the previous summary forward so a second round keeps its context', function (): void {
        [$agent, $task] = seedCompactionTask();

        TaskHistory::create([
            'task_id'                   => $task->id,
            'sequence'                  => 0,
            'role'                      => 'summary',
            'content'                   => 'First round summary.',
            'summarized_sequence_range' => '0-0',
        ]);
        for ($i = 1; $i <= 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 1 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $captured = null;
        $factory  = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver($captured));

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        $contents = array_column($captured->messages, 'content');
        expect($contents)->toContain('First round summary.');
    });

    it('supersedes the previous summary when the new range covers it', function (): void {
        [$agent, $task] = seedCompactionTask();

        for ($i = 0; $i < 8; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn(compactionDriver());

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        // A second round re-summarises the rows the first one replaced plus
        // the ones that followed, so its range starts where the first one's
        // did. The first round's summary row took sequence 8, so the new
        // turns start after it.
        for ($i = 9; $i < 13; $i++) {
            TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $i,
                'role'     => $i % 2 === 0 ? 'user' : 'assistant',
                'content'  => "Message {$i}",
            ]);
        }

        (new ConversationCompactor($factory))->compact($task->id, $agent, 4096, 0.2);

        $summaries = TaskHistory::where('task_id', $task->id)->where('role', 'summary')->get();
        expect($summaries)->toHaveCount(2)
            ->and($summaries[0]->summarized_sequence_range)->toBe('0-2')
            ->and($summaries[1]->summarized_sequence_range)->toBe('0-6');

        // Only the wider summary reaches the model.
        $messages = (new MessageHistoryBuilder())->build($task->id);
        $contents = array_column($messages, 'content');
        expect($contents[0])->toBe('compacted.')
        ->and(array_filter($contents, static fn(string $c): bool => $c === 'compacted.'))->toHaveCount(1);
    });
});
