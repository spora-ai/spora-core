<?php

declare(strict_types=1);

namespace Spora\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Agents\OrchestratorInterface;
use Spora\Drivers\ValueObjects\Usage;
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

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly MercurePublisherInterface $mercure,
        private readonly ?ToolCallSerializer $toolCallSerializer = null,
    ) {}

    /**
     * @inheritDoc
     */
    public function getTasksForUser(int $userId, ?int $agentId = null, ?string $since = null, ?int $page = null, ?int $perPage = null): array
    {
        $query = Task::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->with(['agent']);

        if ($agentId !== null) {
            $query->where('agent_id', $agentId);
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
                'tasks' => $paginator->getCollection()->map(fn(Task $t) => $this->taskListResource($t))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ];
        }

        return $query->get()->map(fn(Task $t) => $this->taskListResource($t))->all();
    }

    /**
     * @inheritDoc
     */
    /**
     * @param list<string> $mediaIds
     */
    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null, array $mediaIds = []): array
    {
        $agent = Agent::where('id', $agentId)->where('user_id', $userId)->first();
        if ($agent === null) {
            throw new InvalidArgumentException('Agent not found.');
        }

        if ($parentTaskId !== null) {
            $parentTask = Task::where('id', $parentTaskId)->where('user_id', $userId)->first();
            if ($parentTask === null) {
                throw new InvalidArgumentException('parent_task_id is invalid.');
            }
        }

        $steps = $maxSteps ?? $agent->max_steps;
        $task = $this->orchestrator->start($agentId, $prompt, $steps, $parentTaskId, null, $mediaIds);

        $resource = $this->taskResource($task);
        $this->mercure->publish($task->id, $userId, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function getTask(int $taskId, int $userId): ?array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            return null;
        }
        return $this->taskResource($task);
    }

    /**
     * @inheritDoc
     */
    public function getTaskWithHistory(int $taskId, int $userId, ?int $sinceSequence = null): ?array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            return null;
        }
        return $this->taskDetailResource($task, $sinceSequence);
    }

    /**
     * @inheritDoc
     */
    public function approveTask(int $taskId, int $userId, array $approvals): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Task is not pending approval.');
        }

        $this->orchestrator->resume($task->id, $approvals);
        $fresh = $task->fresh();

        $resource = $this->taskResource($fresh);
        $this->mercure->publish($fresh->id, $fresh->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function rejectTask(int $taskId, int $userId, string $reason): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Task is not pending approval.');
        }

        $this->orchestrator->reject($task->id, $reason);
        $fresh = $task->fresh();

        $resource = $this->taskResource($fresh);
        $this->mercure->publish($fresh->id, $fresh->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function retryTask(int $taskId, int $userId): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'FAILED') {
            throw new InvalidArgumentException('Only failed tasks can be retried.');
        }

        $newTask = $this->orchestrator->start($task->agent_id, $task->user_prompt, $task->max_steps, null, null, []);

        $resource = $this->taskResource($newTask);
        $this->mercure->publish($newTask->id, $newTask->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    /**
     * @param list<string> $mediaIds
     */
    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null, array $mediaIds = []): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if (!in_array($task->status, ['COMPLETED', 'FAILED'], true)) {
            throw new InvalidArgumentException('Can only continue completed or failed tasks.');
        }

        if ($additionalSteps !== null && ($additionalSteps < 1 || $additionalSteps > 100)) {
            throw new InvalidArgumentException('additional_steps must be an integer between 1 and 100.');
        }

        $continuedTask = $this->orchestrator->continue($task->id, $prompt, $additionalSteps, $mediaIds);

        $resource = $this->taskResource($continuedTask);
        $this->mercure->publish($continuedTask->id, $continuedTask->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function deleteTask(int $taskId, int $userId): bool
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
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
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            return false;
        }

        if ($task->retry_of_task_id === null) {
            throw new InvalidArgumentException('This task is not part of a retry chain.');
        }

        Capsule::table('tasks')
            ->where('user_id', $userId)
            ->where('retry_of_task_id', $task->retry_of_task_id)
            ->where('retry_count', '>=', $task->retry_count)
            ->update(['status' => 'CANCELLED']);

        return true;
    }

    /**
     * @return array{
     *     id: int,
     *     agent_id: int,
     *     status: string,
     *     user_prompt: string,
     *     final_response: string|null,
     *     step_count: int,
     *     max_steps: int,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     parent_task_id?: int,
     *     error_code?: string,
     *     error_message?: string,
     *     retry_of_task_id?: int,
     *     retry_count?: int,
     *     max_retries?: int,
     *     retry_after_minutes?: int,
     *     retry_after?: string,
     *     tool_calls: list<array<string, mixed>>,
     *     history: list<array<string, mixed>>
     * }
     */
    private function taskResource(Task $task): array
    {
        $resource = $this->buildBaseTaskResource($task);

        $serializer = $this->toolCallSerializer ?? new ToolCallSerializer();
        $resource['tool_calls'] = $task->toolCalls->map(fn(ToolCall $tc) => $serializer->toArray($tc))->all();

        $historyPayload = $this->buildHistoryPayload($task->taskHistory()->orderBy('sequence')->get());
        $resource['history'] = $historyPayload['history'];
        $resource['totals'] = self::aggregateUsage($historyPayload['usages']);

        return $resource;
    }

    /**
     * Build the common task fields used by both the detail and list resource views.
     *
     * @return array<string, mixed>
     */
    private function buildBaseTaskResource(Task $task): array
    {
        $resource = [
            'id'             => $task->id,
            'agent_id'       => $task->agent_id,
            'status'         => $task->status,
            'user_prompt'    => $task->user_prompt,
            'final_response' => $task->final_response,
            'step_count'     => $task->step_count,
            'max_steps'      => $task->max_steps,
            'created_at'     => $task->created_at?->toIso8601String(),
            'updated_at'     => $task->updated_at?->toIso8601String(),
        ];

        if ($task->parent_task_id !== null) {
            $resource['parent_task_id'] = $task->parent_task_id;
        }

        if ($task->error_code !== null) {
            $resource['error_code'] = $task->error_code;
            $resource['error_message'] = $task->error_message;
        }

        if ($task->retry_of_task_id !== null) {
            $resource['retry_of_task_id'] = $task->retry_of_task_id;
            $resource['retry_count'] = $task->retry_count;
        } else {
            $resource['max_retries'] = $task->agent->max_retries ?? 0;
            $resource['retry_after_minutes'] = $task->agent->retry_after_minutes ?? 0;
        }

        if ($task->retry_after !== null) {
            $resource['retry_after'] = $task->retry_after->toIso8601String();
        }

        return $resource;
    }

    /**
     * Lightweight task representation for list views.
     * Excludes tool_calls and history to minimise payload size and avoid N+1 queries.
     *
     * @return array{
     *     id: int,
     *     agent_id: int,
     *     status: string,
     *     user_prompt: string,
     *     final_response: string|null,
     *     step_count: int,
     *     max_steps: int,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     parent_task_id?: int,
     *     error_code?: string,
     *     error_message?: string,
     *     retry_of_task_id?: int,
     *     retry_count?: int,
     *     max_retries?: int,
     *     retry_after_minutes?: int,
     *     retry_after?: string
     * }
     */
    private function taskListResource(Task $task): array
    {
        return $this->buildBaseTaskResource($task);
    }

    /**
     * @return array{
     *     id: int,
     *     agent_id: int,
     *     status: string,
     *     user_prompt: string,
     *     final_response: string|null,
     *     step_count: int,
     *     max_steps: int,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     parent_task_id?: int,
     *     error_code?: string,
     *     error_message?: string,
     *     retry_of_task_id?: int,
     *     retry_count?: int,
     *     max_retries?: int,
     *     retry_after_minutes?: int,
     *     retry_after?: string,
     *     tool_calls: list<array{
     *         id: int,
     *         tool_name: string,
     *         tool_type: string,
     *         status: string,
     *         proposed_arguments: array|null,
     *         approved_arguments: array|null,
     *         human_description: string|null,
     *         result_content: string|null,
     *         result_data: array<string,mixed>|null,
     *         executed_at: string|null
     *     }>,
     *     history: list<array<string, mixed>>,
     *     totals: array<string, int>
     * }
     */
    private function taskDetailResource(Task $task, ?int $sinceSequence = null): array
    {
        $resource = $this->taskResource($task);

        $resource['tool_calls'] = $task->toolCalls->map(fn(ToolCall $tc) => [
            'id'                 => $tc->id,
            'provider_call_id'   => $tc->provider_call_id,
            'tool_name'          => $tc->tool_name,
            'tool_type'          => $tc->tool_type,
            'status'             => $tc->status,
            'proposed_arguments' => $tc->proposed_arguments,
            'approved_arguments' => $tc->approved_arguments,
            'human_description'  => $tc->human_description,
            'result_content'     => $tc->result_content,
            'result_data'        => $tc->result_data,
            'executed_at'        => $tc->executed_at?->toIso8601String(),
        ])->all();

        $historyQuery = $task->taskHistory()->orderBy('sequence');
        if ($sinceSequence !== null) {
            $historyQuery->where('sequence', '>', $sinceSequence);
        }

        $historyPayload = $this->buildHistoryPayload($historyQuery->get());
        $resource['history'] = $historyPayload['history'];
        $resource['totals'] = self::aggregateUsage($historyPayload['usages']);

        return $resource;
    }
    /**
     * @return array{history: list<array<string, mixed>>, usages: list<array<string, mixed>>}
     */
    public static function buildHistoryPayload(iterable $historyRows): array
    {
        $rows = [];
        $historyIds = [];
        $usages = [];

        foreach ($historyRows as $row) {
            $historyIds[] = $row->id;
        }
        $usageByHistoryId = self::loadUsageByHistoryIds($historyIds);

        foreach ($historyRows as $row) {
            $usage = $usageByHistoryId[$row->id] ?? null;
            if ($usage !== null) {
                $usages[] = $usage->toArray();
            }
            $rows[] = self::buildHistoryMessage($row, $usage);
        }

        return ['history' => $rows, 'usages' => $usages];
    }

    /**
     * @param list<int> $historyIds
     * @return array<int, Usage>
     */
    public static function loadUsageByHistoryIds(array $historyIds): array
    {
        if ($historyIds === []) {
            return [];
        }

        $rawRows = Capsule::table('usage')
            ->whereIn('task_history_id', $historyIds)
            ->get();

        $result = [];
        foreach ($rawRows as $rawRow) {
            $usage = new Usage(
                inputTokens: (int) ($rawRow->input_tokens ?? 0),
                outputTokens: (int) ($rawRow->output_tokens ?? 0),
                reasoningTokens: (int) ($rawRow->reasoning_tokens ?? 0),
                cachedTokens: (int) ($rawRow->cached_tokens ?? 0),
                cacheCreationTokens: (int) ($rawRow->cache_creation_tokens ?? 0),
                cacheReadTokens: (int) ($rawRow->cache_read_tokens ?? 0),
                provider: (string) ($rawRow->provider ?? 'unknown'),
                rawUsage: self::decodeJson($rawRow->raw_usage ?? null),
                driverMetaInfo: self::decodeJson($rawRow->driver_meta_info ?? null),
            );
            $result[(int) $rawRow->task_history_id] = $usage;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildHistoryMessage(TaskHistory $history, ?Usage $usage = null): array
    {
        $blocks = is_array($history->content_blocks) ? $history->content_blocks : [];
        $message = [
            'sequence' => $history->sequence,
            'role' => $history->role,
            'content' => $history->content,
            'content_blocks' => self::sanitizeContentBlocksForApi($blocks),
            'tool_call_id' => $history->tool_call_id,
            'tool_name' => $history->tool_name,
        ];

        if ($usage !== null) {
            $message['usage'] = self::sanitizeUsageForApi($usage);
        }

        return $message;
    }

    /**
     * Strips server-side-only fields so the admin UI never sees Anthropic
     * signatures or encrypted redacted-thinking payloads.
     *
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    public static function sanitizeContentBlocksForApi(array $blocks): array
    {
        $sanitized = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            unset($block['signature'], $block['data']);
            $sanitized[] = $block;
        }

        return $sanitized;
    }

    public static function sanitizeUsageForApi(Usage $usage): array
    {
        $raw = $usage->toArray();
        unset($raw['raw_usage'], $raw['driver_meta_info']);

        return $raw;
    }

    /**
     * Sums the six token counters across the provided usage payloads. Provider
     * tag and forensics bag are intentionally excluded.
     *
     * @param list<array<string, mixed>> $usages
     * @return array<string, int>
     */
    public static function aggregateUsage(array $usages): array
    {
        $totals = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cached_tokens' => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => 0,
        ];

        foreach ($usages as $usage) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($usage[$key] ?? 0);
            }
        }

        return $totals;
    }

    private static function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
