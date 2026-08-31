<?php

declare(strict_types=1);

namespace Spora\Services;

use Closure;
use InvalidArgumentException;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;

/**
 * Default {@see HandoverServiceInterface} implementation.
 *
 * Validates that the user owns the source task and the target agent,
 * spawns the new task via the orchestrator (which seeds the first
 * `task_history` row from `$summary`), then closes the source task
 * with a `data.handover` breadcrumb so the UI can render the link.
 */
final class HandoverService implements HandoverServiceInterface
{
    /**
     * @param Closure(): OrchestratorInterface $orchestratorFactory
     *   Lazy factory — the Orchestrator is constructed with the tool instance
     *   list (which includes this HandoverTool), so injecting OrchestratorInterface
     *   directly creates a circular dependency. The closure defers resolution
     *   to the moment {@see handover()} is actually called.
     */
    public function __construct(
        private readonly Closure $orchestratorFactory,
        ?PrincipalService $principalService = null,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->principalService = $principalService ?? new PrincipalService(new PrincipalResolver());
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    private readonly PrincipalService $principalService;
    private readonly PrincipalResolver $principalResolver;

    public function handover(
        int $sourceTaskId,
        int $targetAgentId,
        string $summary,
        int $userId,
    ): Task {
        // Source-task gate: any member of a principal that owns the task
        // can hand it off. Matches the widened per-task action gating —
        // hand-off is a "what to do next with this conversation" decision,
        // not a "you must be the original clicker" guard.
        $visiblePrincipalIds = $this->principalResolver->visiblePrincipalIds($userId);
        $source = Task::where('id', $sourceTaskId)
            ->whereIn('principal_id', $visiblePrincipalIds)
            ->first();
        if ($source === null) {
            throw new InvalidArgumentException('Source task not found.');
        }

        // Migration 0067: agents are owned by a principal, not a user_id
        // column directly. Look up the agent and verify the caller controls
        // its principal.
        $targetAgent = Agent::find($targetAgentId);
        if ($targetAgent === null || !$this->principalService->callerControlsPrincipal($userId, (int) $targetAgent->principal_id)) {
            throw new InvalidArgumentException('Target agent not found.');
        }

        // parent_task_id is the lineage breadcrumb: the target task knows
        // which source conversation produced it, so the UI can later show
        // a "handed off from #X" link without re-scanning history.
        $newTask = ($this->orchestratorFactory)()->start(
            agentId: $targetAgent->id,
            userPrompt: $summary,
            maxSteps: (int) ($targetAgent->max_steps ?? 10),
            parentTaskId: $source->id,
            userId: $userId,
        );

        $source->update([
            'status'         => 'COMPLETED',
            'final_response' => "Handed off to {$targetAgent->name}.",
            'data'           => array_merge($source->data ?? [], [
                'handover' => [
                    'target_task_id'   => $newTask->id,
                    'target_agent_id'  => $targetAgent->id,
                    'target_agent_name' => $targetAgent->name,
                ],
            ]),
        ]);

        return $newTask;
    }
}
