<?php

declare(strict_types=1);

use Spora\Agents\ContextWindowRecovery;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
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
        'user_id'           => null,
        'name'              => 'CWR Test Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);
    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'CWR Test Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => $systemPrompt,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'user_id'     => $userId,
        'agent_id'    => $agent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'compact test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    return [$agent, $task];
}

describe('ContextWindowRecovery::compactHistory', function (): void {
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

        $capturedRef = null;
        $driver = Mockery::mock(LLMDriverInterface::class);
        $driver->allows('complete')
            ->andReturnUsing(static function (LLMRequest $req) use (&$capturedRef): LLMResponse {
                $capturedRef = $req;
                return new LLMResponse('compacted.', [], 10, 5, 'cmp_summary');
            });
        $driver->allows('getProviderName')->andReturn('mock');
        $driver->allows('getModelName')->andReturn('mock-model');

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($driver);

        $orch = new Orchestrator(
            $factory,
            new OrchestratorConfig(toolInstances: []),
        );

        $recovery = new ContextWindowRecovery($orch, $factory);
        $method = (new ReflectionClass($recovery))->getMethod('compactHistory');
        $method->invoke($recovery, $task->id, 4096, 0.2, $agent);

        expect($capturedRef)->not->toBeNull();
        $messages = $capturedRef->messages;

        // compactHistory rephrases `role:'tool'` to `role:'user'`
        // with `[tool:<name>]` prefix and runs ScrubDataUrls over the
        // content before sending to the summarizer.
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

        // compactHistory is also expected to write a `summary` row that
        // replaces the summarized range — verify the side effect.
        $summaryRow = TaskHistory::where('task_id', $task->id)
            ->where('role', 'summary')
            ->first();
        expect($summaryRow)->not->toBeNull()
            ->and($summaryRow->content)->toBe('compacted.')
            ->and($summaryRow->summarized_sequence_range)->toBe('0-2');
    });
});
