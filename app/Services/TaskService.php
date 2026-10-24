<?php

declare(strict_types=1);

namespace Spora\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Throwable;

/**
 * Handles task CRUD operations, lifecycle state transitions, and real-time notifications.
 */
final class TaskService implements TaskServiceInterface
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    private AnswerTaskRecorder $answerRecorder;
    private TaskResourceBuilder $resources;

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly MercurePublisherInterface $mercure,
        private readonly TaskCompactionServiceInterface $compactionService,
        ?ToolCallSerializer $toolCallSerializer = null,
        private readonly ?PrincipalResolver $principalResolver = null,
    ) {
        $this->answerRecorder = new AnswerTaskRecorder($this->orchestrator, $this->principalResolver);
        $this->resources = new TaskResourceBuilder($toolCallSerializer);
    }

    /**
     * @inheritDoc
     */
    public function getTasksForUser(int $userId, ?int $agentId = null, ?string $since = null, ?int $page = null, ?int $perPage = null, ?string $status = null): array
    {
        // Visibility scoping: every principal the user can act as (their
        // own user-principal + group-principals for every group they
        // belong to) confers visibility on tasks owned by that principal.
        // This widens the legacy `where('user_id', $userId)` gate so a
        // group member can see every other member's runs on a shared
        // agent. Migrated from `tasks.user_id` to `tasks.principal_id`
        // — see migration 0073.
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $query = Task::whereIn('principal_id', $visiblePrincipalIds)
            ->orderByDesc('updated_at')
            ->with(['agent']);

        if ($agentId !== null) {
            $query->where('agent_id', $agentId);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($since !== null) {
            try {
                $query->where('updated_at', '>', Carbon::parse($since)->utc());
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }

        if ($page !== null) {
            $perPage = $perPage ?? 20;
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            return [
                'tasks' => $paginator->getCollection()->map(fn(Task $t) => $this->resources->taskListResource($t))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ];
        }

        return $query->get()->map(fn(Task $t) => $this->resources->taskListResource($t))->all();
    }

    /**
     * @inheritDoc
     */
    /**
     * @param list<string> $mediaIds
     */
    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null, array $mediaIds = []): array
    {
        // Migration 0067 cut `agents.user_id`; ownership now lives on
        // `agents.principal_id`. The check routes through PrincipalResolver so
        // shared/group-owned agents are reachable by group members.
        $principalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $agentQuery = Agent::where('id', $agentId);
        if ($principalIds === []) {
            $agentQuery->whereRaw('1 = 0');
        } else {
            $agentQuery->whereIn('principal_id', $principalIds);
        }
        $agent = $agentQuery->first();
        if ($agent === null) {
            throw new InvalidArgumentException('Agent not found.');
        }

        if ($parentTaskId !== null) {
            $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
            $parentTask = Task::where('id', $parentTaskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
            if ($parentTask === null) {
                throw new InvalidArgumentException('parent_task_id is invalid.');
            }
        }

        $steps = $maxSteps ?? $agent->max_steps;
        $task = $this->orchestrator->start($agentId, $prompt, $steps, $parentTaskId, null, $mediaIds, $userId);

        $resource = $this->resources->taskResource($task);
        $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function getTask(int $taskId, int $userId): ?array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            return null;
        }
        return $this->resources->taskResource($task);
    }

    /**
     * @inheritDoc
     */
    public function getTaskWithHistory(int $taskId, int $userId, ?int $sinceSequence = null): ?array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            return null;
        }
        return $this->resources->taskDetailResource($task, $sinceSequence);
    }

    /**
     * @inheritDoc
     */
    public function approveTask(int $taskId, int $userId, array $decisions): array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Task is not pending approval.');
        }

        $this->orchestrator->resume($task->id, $decisions);
        $fresh = $task->fresh();

        $resource = $this->resources->taskResource($fresh);
        $this->mercure->publishForPrincipal($fresh->id, $fresh->principalOwnerId(), $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function rejectTask(int $taskId, int $userId, string $reason): array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Task is not pending approval.');
        }

        $this->orchestrator->reject($task->id, $reason);
        $fresh = $task->fresh();

        $resource = $this->resources->taskResource($fresh);
        $this->mercure->publishForPrincipal($fresh->id, $fresh->principalOwnerId(), $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function retryTask(int $taskId, int $userId): array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'FAILED') {
            throw new InvalidArgumentException('Only failed tasks can be retried.');
        }

        $retried = $this->orchestrator->retry($task->id);

        $resource = $this->resources->taskResource($retried);
        $this->mercure->publishForPrincipal($retried->id, $retried->principalOwnerId(), $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function compactTask(int $taskId, int $userId): array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        // A running task is mid-turn: the tick writes rows as it goes, and a
        // compaction would race it.
        if (!in_array($task->status, ['COMPLETED', 'FAILED', 'ABORTED'], true)) {
            throw new InvalidArgumentException('Can only compact completed, failed, or aborted tasks.');
        }

        $compacted = $this->compactionService->compact($task->id);

        $fresh = $task->fresh();
        $resource = $this->resources->taskDetailResource($fresh);
        $this->mercure->publishForPrincipal($fresh->id, $fresh->principalOwnerId(), $resource);

        return ['task' => $resource, 'compacted' => $compacted];
    }

    /**
     * @inheritDoc
     */
    /**
     * @param list<string> $mediaIds
     */
    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null, array $mediaIds = []): array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if (!in_array($task->status, ['COMPLETED', 'FAILED', 'ABORTED', 'RUNNING'], true)) {
            throw new InvalidArgumentException(
                'Can only continue completed, failed, aborted, or running tasks.',
            );
        }

        if ($additionalSteps !== null && ($additionalSteps < 1 || $additionalSteps > 100)) {
            throw new InvalidArgumentException('additional_steps must be an integer between 1 and 100.');
        }

        try {
            $continuedTask = $this->orchestrator->continue($task->id, $prompt, $additionalSteps, $mediaIds);
        } catch (InvalidTaskTransitionException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $resource = $this->resources->taskResource($continuedTask);
        $this->mercure->publishForPrincipal($continuedTask->id, $continuedTask->principalOwnerId(), $resource);

        return $resource;
    }

    /**
     * Aborts the running agent loop for `$taskId`. Validates the
     * user-ownership, delegates the state transition to
     * {@see Orchestrator::abort()}, and publishes the new state to
     * Mercure.
     *
     * Throws {@see InvalidArgumentException} with `"Task not found."`
     * when the task is missing or not owned by the calling user.
     */
    public function abortTask(int $taskId, int $userId): array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        try {
            $aborted = $this->orchestrator->abort($task->id);
        } catch (InvalidTaskTransitionException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        } catch (ModelNotFoundException $e) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND, 0, $e);
        }

        $resource = $this->resources->taskResource($aborted);
        $this->mercure->publishForPrincipal($aborted->id, $aborted->principalOwnerId(), $resource);

        return $resource;
    }

    /**
     * Aborts a sub-agent child task and cascades the abort up the parent
     * chain. The caller (typically the SubAgentStopWaiting affordance
     * on a tool-call widget) only needs to own the child — ancestors
     * are system-aborted and informed via Mercure.
     *
     * Implementation lives in {@see cascadeAbortToAncestors()} so the
     * sub-agent helper can call just the cascade portion without
     * running this entry point.
     */
    public function abortSubAgentAndCascade(int $childTaskId, int $userId): array
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $child = Task::where('id', $childTaskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($child === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        try {
            $abortedChild = $this->orchestrator->abort($child->id);
        } catch (InvalidTaskTransitionException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        } catch (ModelNotFoundException $e) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND, 0, $e);
        }

        $this->cascadeAbortToAncestors((int) $child->parent_task_id);

        $fresh = $abortedChild->fresh();
        $resource = $this->resources->taskResource($fresh);
        $this->mercure->publishForPrincipal($fresh->id, $fresh->principalOwnerId(), $resource);

        return $resource;
    }

    /**
     * Records the operator's answers to a pending `ask_user_question`
     * batch on an `AWAITING_INPUT` task, flips the task back to
     * `QUEUED` (or leaves it in `AWAITING_INPUT` if other batches are
     * still pending), and publishes the new state to Mercure.
     *
     * Mercure publish is best-effort: the controller has already
     * returned 204 by the time this method is reached, so a publish
     * failure must not fail an otherwise-successful answer submit.
     * `MercurePublisher::doPublish` logs the underlying transport
     * error internally and returns `false`; this method additionally
     * catches anything that escapes that layer (interface swaps,
     * pluggable implementations) so a wedged hub cannot regress a
     * correct answer into a 502. Clients whose subscription is the
     * Mercure SSE event still see the new state on the next poll of
     * `GET /api/v1/tasks/{id}` after the row flips.
     *
     * Throws {@see InvalidArgumentException} when the task is missing,
     * not owned by the calling user, or not in `AWAITING_INPUT`.
     *
     * @return array<string, mixed>
     */
    public function answerTask(int $taskId, int $userId, string $toolCallId, string $formattedContent): array
    {
        $result = $this->answerRecorder->record($taskId, $userId, $toolCallId, $formattedContent);
        $resource = $this->resources->taskResource($result['task']);
        $this->publishAnsweredTaskBestEffort($taskId, $resource, $result['principal_id']);
        return $resource;
    }

    /**
     * Best-effort Mercure publish — see method docblock. The controller
     * returns 200 with the new task resource; a publish failure here is
     * logged by MercurePublisher and the next poll of GET
     * /api/v1/tasks/{id} re-syncs the client.
     *
     * @param array<string, mixed> $resource
     */
    private function publishAnsweredTaskBestEffort(int $taskId, array $resource, int $principalId): void
    {
        try {
            $this->mercure->publishForPrincipal($taskId, $principalId, $resource);
        } catch (Throwable) {
            // Mirrors MercurePublisher::doPublish's own try/catch
            // (app/Services/MercurePublisher.php:122) — nothing else to
            // do here; the row state is already committed.
        }
    }

    /**
     * Walks up the `parent_task_id` chain from `$parentTaskId` (nullable
     * when aborting a root task) and aborts every ancestor that is still
     * in `AWAITING_SUB_AGENTS`. Idempotent: ancestors that are already
     * `ABORTED` or any other state are left untouched. Publishes once
     * per affected ancestor inside the loop — one Mercure event per
     * cascaded parent keeps the live stream useful. Already-ABORTED
     * ancestors are skipped before reaching `orchestrator->abort`.
     */
    private function cascadeAbortToAncestors(?int $parentTaskId): void
    {
        $cursor = $parentTaskId;
        $cascadeTargets = [];
        // Defence against a corrupt parent_task_id cycle (e.g. A→B→A)
        // turning the walk into an infinite loop.
        $visited = [];

        while ($cursor !== null) {
            if (isset($visited[$cursor])) {
                break;
            }
            $visited[$cursor] = true;

            $row = Task::find($cursor);
            if ($row === null) {
                break;
            }

            if ($row->status === 'AWAITING_SUB_AGENTS') {
                $cascadeTargets[] = $row;
            }

            $cursor = $row->parent_task_id !== null ? (int) $row->parent_task_id : null;
        }

        foreach ($cascadeTargets as $target) {
            try {
                $aborted = $this->orchestrator->abort((int) $target->id);
                $resource = $this->resources->taskResource($aborted);
                $this->mercure->publishForPrincipal($aborted->id, $aborted->principalOwnerId(), $resource);
            } catch (InvalidTaskTransitionException $e) {
                // Ancestor transitioned between the cascade scan and the
                // per-row abort — treat as a no-op for the cascading call.
                continue;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteTask(int $taskId, int $userId): bool
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            return false;
        }

        Capsule::connection()->transaction(function () use ($task): void {
            if ($task->retry_of_task_id === null) {
                Task::where('retry_of_task_id', $task->id)->delete();
            }
            TaskHistory::where('task_id', $task->id)->delete();
            ToolCall::where('task_id', $task->id)->delete();
            $task->delete();
        });

        return true;
    }

    /**
     * @inheritDoc
     */
    public function cancelRetryChain(int $taskId, int $userId): bool
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            return false;
        }

        if ($task->retry_of_task_id === null) {
            throw new InvalidArgumentException('This task is not part of a retry chain.');
        }

        // In-place retry means the "chain" is the failed task itself. Clearing
        // `retry_after` is enough to stop the worker from re-ticking it; the
        // task stays FAILED so the user can still see the failure or click
        // Retry Now manually.
        //
        // Filter on the loaded task's principal_id (not the caller's
        // user-principal) — every task in a retry chain shares the same
        // agent → same principal_id, so scoping to that principal
        // correctly catches every chain row regardless of which
        // group member triggered the cancel. Filter on the user's
        // own user-principal would silently no-op for group-shared
        // chains (their principal_id is the group, not the caller).
        Capsule::table('tasks')
            ->where('principal_id', (int) $task->principal_id)
            ->where('retry_of_task_id', $task->retry_of_task_id)
            ->where('retry_count', '>=', $task->retry_count)
            ->where('status', 'FAILED')
            ->update([
                'retry_after'      => null,
                'retry_of_task_id' => null,
                'retry_count'      => 0,
            ]);

        return true;
    }
}
