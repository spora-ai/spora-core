<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AgentApi;

use Spora\Agents\TaskLifecyclePolicy;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Spora\Services\AgentApi\AgentApiChatProjection;
use Tests\Concerns\CreatesPrincipal;

/**
 * The wire shapes the Agent API projects a chat into. The difference between
 * `/status`, `/messages` and `/transcript` is the point of the feature, so
 * each is pinned here separately from the HTTP layer.
 */
describe('AgentApiChatProjection', function (): void {
    uses(CreatesPrincipal::class);

    function projection(): AgentApiChatProjection
    {
        return new AgentApiChatProjection();
    }

    /**
     * A task on a freshly registered user's agent. `tasks.principal_id` is
     * NOT NULL since migration 0073, so the agent's principal is copied
     * rather than left to a default.
     *
     * @param array<string, mixed> $attributes
     */
    function task(array $attributes = []): Task
    {
        $auth = bootAuthLayer();
        static $seq = 0;
        $seq++;

        $userId = $auth->register("proj{$seq}@example.com", 'Password1!', "Proj{$seq}");
        $principalId = createUserPrincipalPublic($userId);

        $agent = Agent::create([
            'principal_id' => $principalId,
            'name' => "Projection {$seq}",
            'max_steps' => 10,
            'is_active' => true,
        ]);

        return Task::create($attributes + [
            'agent_id' => $agent->id,
            'principal_id' => $principalId,
            'status' => 'COMPLETED',
            'user_prompt' => 'hi',
        ]);
    }

    it('reports a status and an answer, and nothing else', function (): void {
        $status = projection()->status(task(['final_response' => 'the answer']));

        expect($status['status'])->toBe('COMPLETED');
        expect($status['answer'])->toBe('the answer');
        expect($status['error'])->toBeNull();
        expect($status['blocked'])->toBeNull();
        expect($status)->toHaveKeys(['id', 'agent_id', 'status', 'answer', 'error', 'blocked', 'created_at', 'updated_at']);
    });

    it('falls back to the last assistant turn when there is no final answer', function (): void {
        $task = task(['status' => 'ABORTED', 'final_response' => null]);

        $task->taskHistory()->create(['sequence' => 0, 'role' => 'user', 'content' => 'hi']);
        $task->taskHistory()->create(['sequence' => 1, 'role' => 'assistant', 'content' => 'partial work']);

        expect(projection()->status($task)['answer'])->toBe('partial work');
    });

    it('surfaces a failure as an error rather than an answer', function (): void {
        $status = projection()->status(task([
            'status' => 'FAILED',
            'error_code' => 'LLM_RATE_LIMIT',
            'error_message' => 'Upstream is rate limiting.',
        ]));

        expect($status['error'])->toBe(['code' => 'LLM_RATE_LIMIT', 'message' => 'Upstream is rate limiting.']);
    });

    it('reports a pending tool call as a block', function (): void {
        $task = task(['status' => 'PENDING_APPROVAL', 'user_prompt' => 'send it']);

        ToolCall::create([
            'task_id' => $task->id,
            'agent_id' => $task->agent_id,
            'provider_call_id' => 'pc_send_1',
            'tool_name' => 'send_email',
            'tool_class' => 'Spora\\Tools\\SendEmailTool',
            'tool_type' => 'output',
            'status' => 'PENDING_APPROVAL',
            'operation' => 'send',
            'proposed_arguments' => ['to' => 'a@example.com'],
            'human_description' => 'Email a@example.com',
        ]);

        $blocked = projection()->blocked($task);

        expect($blocked['reason'])->toBe('PENDING_APPROVAL');
        expect($blocked['tool_calls'])->toHaveCount(1);
        expect($blocked['tool_calls'][0]['name'])->toBe('send_email');
        expect($blocked['tool_calls'][0]['operation'])->toBe('send');
        expect($blocked['tool_calls'][0]['arguments'])->toBe(['to' => 'a@example.com']);
    });

    it('does not call AWAITING_SUB_AGENTS a block', function (): void {
        $task = task(['status' => 'AWAITING_SUB_AGENTS']);

        // A sub-agent resolving on its own is progress, not a stall the
        // caller has to clear.
        expect(projection()->blocked($task))->toBeNull();
        expect(projection()->hasSettled($task))->toBeFalse();
    });

    it('agrees with itself about what has settled', function (string $status, bool $settled): void {
        $policy = new TaskLifecyclePolicy();

        expect(projection()->hasSettled(task(['status' => $status])))->toBe($settled);
        // A settle is either terminal, aborted, or something only the caller
        // can clear — never a transient running state. Restated rather than
        // read back, so the two assertions cannot pass on the same mistake.
        expect($settled)->toBe(
            $policy->isTerminal($status)
            || $status === 'ABORTED'
            || in_array($status, AgentApiChatProjection::BLOCKED_STATUSES, true),
        );
    })->with([
        'COMPLETED' => ['COMPLETED', true],
        'FAILED' => ['FAILED', true],
        'CANCELLED' => ['CANCELLED', true],
        'ABORTED' => ['ABORTED', true],
        'PENDING_APPROVAL' => ['PENDING_APPROVAL', true],
        'AWAITING_INPUT' => ['AWAITING_INPUT', true],
        'AWAITING_SUB_AGENTS' => ['AWAITING_SUB_AGENTS', false],
        'RUNNING' => ['RUNNING', false],
        'QUEUED' => ['QUEUED', false],
    ]);

    it('keeps tool turns out of the conversation view', function (): void {
        $task = task(['final_response' => 'done']);

        $task->taskHistory()->create(['sequence' => 0, 'role' => 'user', 'content' => 'hi']);
        $task->taskHistory()->create([
            'sequence' => 1,
            'role' => 'assistant',
            'content' => null,
            'tool_call_payload' => json_encode([['id' => 'pc1', 'type' => 'function']]),
        ]);
        $task->taskHistory()->create(['sequence' => 2, 'role' => 'tool', 'tool_name' => 'stub_input', 'content' => 'r']);
        $task->taskHistory()->create(['sequence' => 3, 'role' => 'assistant', 'content' => 'done']);

        $messages = projection()->messages((int) $task->id, null, 'https://spora.test');

        expect(array_column($messages, 'role'))->toBe(['user', 'assistant']);
        expect(array_column($messages, 'text'))->toBe(['hi', 'done']);
    });

    it('gives every attachment an absolute agent-api URL', function (): void {
        $task = task(['user_prompt' => 'look']);

        $task->taskHistory()->create([
            'sequence' => 0,
            'role' => 'user',
            'content' => 'look',
            'attachments' => [['media_id' => 'm-1', 'kind' => 'image']],
        ]);

        $messages = projection()->messages((int) $task->id, null, 'https://spora.test/');

        expect($messages[0]['attachments'])->toBe([
            [
                'media_id' => 'm-1',
                'kind' => 'image',
                'url' => 'https://spora.test/api/v1/agent-api/media/m-1',
            ],
        ]);
    });

    it('omits attachments when a turn has none', function (): void {
        $task = task();

        $task->taskHistory()->create(['sequence' => 0, 'role' => 'user', 'content' => 'hi']);

        expect(projection()->messages((int) $task->id, null, 'https://spora.test')[0]['attachments'])->toBeNull();
    });

    it('filters by since_sequence', function (): void {
        $task = task(['final_response' => 'done']);

        $task->taskHistory()->create(['sequence' => 0, 'role' => 'user', 'content' => 'first']);
        $task->taskHistory()->create(['sequence' => 1, 'role' => 'assistant', 'content' => 'done']);

        expect(projection()->messages((int) $task->id, 0, 'https://spora.test'))->toHaveCount(1);
    });
});
