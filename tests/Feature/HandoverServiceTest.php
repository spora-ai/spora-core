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
        'principal_id' => createUserPrincipalPublic($userId),
        'name'           => 'Source Agent',
        'llm_provider'   => 'mock',
        'llm_model'      => 'mock',
        'max_steps'      => 10,
        'is_active'      => true,
    ]);
    $targetAgent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
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
            'principal_id' => createUserPrincipalPublic($userId),
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
            ->with($targetAgentId, 'ctx', 7, $source->id, null, [], $userId)
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
        expect($source->data['handover']['target_agent_name'])->toBe('Target Agent');
    });

    it('throws when the source task is not owned by the user', function (): void {
        [$service, $orchestrator] = makeHandoverService();
        [$userId, $sourceAgentId, $targetAgentId] = makeHandoverFixture();

        $otherAuth = bootAuthLayer();
        $otherUserId = $otherAuth->register('handover-other@example.com', 'Password1!', 'Other');

        $foreignSource = Task::create([
            'principal_id' => createUserPrincipalPublic($otherUserId),
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
            'principal_id' => createUserPrincipalPublic($otherUserId),
            'name'         => 'Foreign Agent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $source = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
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
            'principal_id' => createUserPrincipalPublic($userId),
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

    it('throws when the target agent is in the stored allowlist but owned by a different principal', function (): void {
        // Defense in depth: the HandoverTool allowlist may be tampered with or
        // a foreign id slipped in via copy-paste. The service still refuses
        // via `callerControlsPrincipal` — the second layer behind the
        // HandoverTool boundary.
        [$service, $orchestrator] = makeHandoverService();
        [$userId, $sourceAgentId, $targetAgentId] = makeHandoverFixture();

        $otherAuth = bootAuthLayer();
        $otherUserId = $otherAuth->register('handover-xprinc@example.com', 'Password1!', 'XPrinc');
        $foreignAgent = Agent::create([
            'principal_id' => createUserPrincipalPublic($otherUserId),
            'name'         => 'Foreign X-Principal Agent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        // Pretend the operator stored an allowlist that contains the foreign id.
        // The service doesn't read the allowlist itself, but the test pins the
        // contract: even when the caller and the orchestrator think the foreign
        // id is "allowed", `callerControlsPrincipal` blocks the dispatch.
        $config = Mockery::mock(Spora\Services\ToolConfigServiceInterface::class);
        $config->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => [$foreignAgent->id, $targetAgentId]]);

        $source = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'user_id'      => $userId,
            'agent_id'     => $sourceAgentId,
            'status'       => 'RUNNING',
            'user_prompt'  => 'Original',
            'max_steps'    => 10,
        ]);

        $orchestrator->shouldNotReceive('start');

        expect(fn() => $service->handover(
            sourceTaskId: $source->id,
            targetAgentId: $foreignAgent->id,
            summary: 'ctx',
            userId: $userId,
        ))->toThrow(InvalidArgumentException::class, 'Target agent not found.');
    });
});

describe('SubAgentService::spawn', function (): void {

    it('throws when the target agent is in the stored allowlist but owned by a different principal', function (): void {
        // Mirror of the HandoverService cross-principal test for the
        // `sub_agent` op. The service-level `callerControlsPrincipal`
        // check must reject a foreign target regardless of what the
        // HandoverTool's stored allowlist says.
        $orchestrator = Mockery::mock(OrchestratorInterface::class);

        $auth = bootAuthLayer();
        $userId = $auth->register('subagent-xprinc@example.com', 'Password1!', 'SubXPrinc');

        $sourceAgent = Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name'         => 'SubAgent Source',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 10,
            'is_active'    => true,
        ]);

        $otherAuth = bootAuthLayer();
        $otherUserId = $otherAuth->register('subagent-xprinc-other@example.com', 'Password1!', 'SubXPrincOther');
        $foreignAgent = Agent::create([
            'principal_id' => createUserPrincipalPublic($otherUserId),
            'name'         => 'Foreign SubAgent Target',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $source = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'user_id'      => $userId,
            'agent_id'     => $sourceAgent->id,
            'status'       => 'RUNNING',
            'user_prompt'  => 'Original',
            'max_steps'    => 10,
        ]);

        $subAgent = new Spora\Services\SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
            null,
            Spora\Agents\ValueObjects\WorkerMode::Sync,
        );

        $orchestrator->shouldNotReceive('start');

        expect(fn() => $subAgent->spawn(
            parentTaskId: $source->id,
            targetAgentId: $foreignAgent->id,
            prompt: 'ctx',
            userId: $userId,
        ))->toThrow(InvalidArgumentException::class, 'Parent task or target agent not found.');
    });
});
