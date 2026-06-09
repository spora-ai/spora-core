<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\HandoverService;

/**
 * Build a HandoverService backed by a Mockery Orchestrator so we never
 * invoke the real driver loop. The mock's `start()` returns a freshly
 * created Task with `parent_task_id` plumbed through.
 */
function makeHandoverService(): array
{
    $orchestrator = Mockery::mock(OrchestratorInterface::class);

    return [new HandoverService(static fn(): OrchestratorInterface => $orchestrator), $orchestrator];
}

/**
 * Register a user and create two agents under their ownership. Returns
 * `[userId, sourceAgentId, targetAgentId]`.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function makeHandoverFixture(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = $authService->register(
        "handover-{$seq}@example.com",
        'Password1!',
        "Handover{$seq}",
    );

    $sourceAgent = Agent::create([
        'user_id'        => $userId,
        'name'           => 'Source Agent',
        'llm_provider'   => 'mock',
        'llm_model'      => 'mock',
        'max_steps'      => 10,
        'is_active'      => true,
    ]);
    $targetAgent = Agent::create([
        'user_id'        => $userId,
        'name'           => 'Target Agent',
        'llm_provider'   => 'mock',
        'llm_model'      => 'mock',
        'max_steps'      => 7,
        'is_active'      => true,
    ]);

    return [$userId, $sourceAgent->id, $targetAgent->id];
}

describe('HandoverService::handover', function (): void {

    it('creates a new task with parent_task_id, completes the source, and merges data.handover', function (): void {
        [$service, $orchestrator] = makeHandoverService();
        [$userId, $sourceAgentId, $targetAgentId] = makeHandoverFixture();

        $source = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $sourceAgentId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Original prompt',
            'max_steps'   => 10,
        ]);

        $newTask = new Task();
        $newTask->id = 12345;
        $newTask->agent_id = $targetAgentId;
        $newTask->user_id = $userId;
        $newTask->parent_task_id = $source->id;
        $newTask->status = 'RUNNING';
        $newTask->user_prompt = 'ctx';

        $orchestrator->allows('start')
            ->with($targetAgentId, 'ctx', 7, $source->id)
            ->andReturn($newTask);

        $returned = $service->handover(
            sourceTaskId: $source->id,
            targetAgentId: $targetAgentId,
            summary: 'ctx',
            userId: $userId,
        );

        expect($returned->id)->toBe(12345);
        expect($returned->parent_task_id)->toBe($source->id);

        $source->refresh();
        expect($source->status)->toBe('COMPLETED');
        expect($source->final_response)->toBe('Handed off to Target Agent.');
        expect($source->data['handover']['target_task_id'])->toBe(12345);
        expect($source->data['handover']['target_agent_id'])->toBe($targetAgentId);
    });

    it('throws when the source task is not owned by the user', function (): void {
        [$service, $orchestrator] = makeHandoverService();
        [$userId, $sourceAgentId, $targetAgentId] = makeHandoverFixture();

        $otherAuth = bootAuthLayer();
        $otherUserId = $otherAuth->register('handover-other@example.com', 'Password1!', 'Other');

        $foreignSource = Task::create([
            'user_id'     => $otherUserId,
            'agent_id'    => $sourceAgentId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Foreign prompt',
            'max_steps'   => 10,
        ]);

        $orchestrator->shouldNotReceive('start');

        expect(fn() => $service->handover(
            sourceTaskId: $foreignSource->id,
            targetAgentId: $targetAgentId,
            summary: 'ctx',
            userId: $userId,
        ))->toThrow(InvalidArgumentException::class, 'Source task not found.');
    });

    it('throws when the target agent is not owned by the user', function (): void {
        [$service, $orchestrator] = makeHandoverService();
        [$userId, $sourceAgentId, $targetAgentId] = makeHandoverFixture();

        $otherAuth = bootAuthLayer();
        $otherUserId = $otherAuth->register('handover-other2@example.com', 'Password1!', 'Other2');
        $foreignAgent = Agent::create([
            'user_id'      => $otherUserId,
            'name'         => 'Foreign Agent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $source = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $sourceAgentId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Original',
            'max_steps'   => 10,
        ]);

        $orchestrator->shouldNotReceive('start');

        expect(fn() => $service->handover(
            sourceTaskId: $source->id,
            targetAgentId: $foreignAgent->id,
            summary: 'ctx',
            userId: $userId,
        ))->toThrow(InvalidArgumentException::class, 'Target agent not found.');
    });

    it('preserves existing source task data when merging the handover breadcrumb', function (): void {
        [$service, $orchestrator] = makeHandoverService();
        [$userId, $sourceAgentId, $targetAgentId] = makeHandoverFixture();

        $source = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $sourceAgentId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Original',
            'max_steps'   => 10,
            'data'        => ['foo' => 'bar', 'count' => 3],
        ]);

        $newTask = new Task();
        $newTask->id = 7777;
        $newTask->agent_id = $targetAgentId;
        $newTask->user_id = $userId;
        $newTask->parent_task_id = $source->id;
        $newTask->status = 'RUNNING';
        $newTask->user_prompt = 'ctx';

        $orchestrator->allows('start')->andReturn($newTask);

        $service->handover(
            sourceTaskId: $source->id,
            targetAgentId: $targetAgentId,
            summary: 'ctx',
            userId: $userId,
        );

        $source->refresh();
        expect($source->data['foo'])->toBe('bar');
        expect($source->data['count'])->toBe(3);
        expect($source->data['handover']['target_task_id'])->toBe(7777);
    });
});
