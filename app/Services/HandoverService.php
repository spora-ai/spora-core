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
    ) {}

    public function handover(
        int $sourceTaskId,
        int $targetAgentId,
        string $summary,
        int $userId,
    ): Task {
        $source = Task::where('id', $sourceTaskId)
            ->where('user_id', $userId)
            ->first();
        if ($source === null) {
            throw new InvalidArgumentException('Source task not found.');
        }

        $targetAgent = Agent::where('id', $targetAgentId)
            ->where('user_id', $userId)
            ->first();
        if ($targetAgent === null) {
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
        );

        $source->update([
            'status'         => 'COMPLETED',
            'final_response' => "Handed off to {$targetAgent->name}.",
            'data'           => array_merge($source->data ?? [], [
                'handover' => [
                    'target_task_id'  => $newTask->id,
                    'target_agent_id' => $targetAgent->id,
                ],
            ]),
        ]);

        return $newTask;
    }
}
