<?php

declare(strict_types=1);

namespace Spora\Metering;

use Spora\Models\Task;

/**
 * Builds a {@see MeteringContext} from a task.
 *
 * A scheduled run is a task whose `data` carries a `run_id` — the marker
 * {@see \Spora\Agents\Orchestrator::start()} writes for a run the platform
 * started rather than a user. A meter uses it to tell "a person asked for
 * this" apart from "the platform asked for this", which is what decides
 * whether an individual can be charged alongside the owner.
 */
final class MeteringContextFactory
{
    public function forTask(Task $task): MeteringContext
    {
        return new MeteringContext(
            taskId: (int) $task->id,
            agentId: (int) $task->agent_id,
            principalId: (int) $task->principal_id,
            triggerUserId: $task->trigger_user_id === null ? null : (int) $task->trigger_user_id,
            isSystemRun: $this->isSystemRun($task),
        );
    }

    /**
     * Null when the task does not exist — a task that was deleted mid-tick
     * cannot be metered, and a meter that cannot be asked must not stop
     * the work it was supposed to measure.
     */
    public function forTaskId(int $taskId): ?MeteringContext
    {
        $task = Task::find($taskId);

        return $task === null ? null : $this->forTask($task);
    }

    private function isSystemRun(Task $task): bool
    {
        $data = $task->data;

        return is_array($data) && isset($data['run_id']);
    }
}
