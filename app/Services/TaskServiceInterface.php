<?php

declare(strict_types=1);

namespace Spora\Services;

interface TaskServiceInterface
{
    /**
     * Returns paginated tasks when $page is given, otherwise all tasks.
     *
     * @return array{
     *     tasks: array<array{
     *         id: int,
     *         agent_id: int,
     *         status: string,
     *         user_prompt: string,
     *         final_response: string|null,
     *         step_count: int,
     *         max_steps: int,
     *         created_at: string|null,
     *         updated_at: string|null,
     *         parent_task_id?: int,
     *         error_code?: string,
     *         error_message?: string,
     *         retry_of_task_id?: int,
     *         retry_count?: int,
     *         max_retries?: int,
     *         retry_after_minutes?: int,
     *         retry_after?: string
     *     }>,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int}
     * }|array<array{
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
     * }>
     */
    public function getTasksForUser(int $userId, ?int $agentId = null, ?string $since = null, ?int $page = null, ?int $perPage = null): array;

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
     *     retry_after?: string
     * }
     */
    /**
     * @param list<string> $mediaIds
     */
    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null, array $mediaIds = []): array;

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
     *     retry_after?: string
     * }|null
     */
    public function getTask(int $taskId, int $userId): ?array;

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
     *     history: list<array{
     *         sequence: int,
     *         role: string,
     *         content: string|null,
     *         content_blocks: list<array<string, mixed>>,
     *         tool_call_id: string|null,
     *         tool_name: string|null,
     *         usage?: array<string, mixed>|null
     *     }>,
     *     totals: array<string, int>
     * }|null
     */
    public function getTaskWithHistory(int $taskId, int $userId, ?int $sinceSequence = null): ?array;

    /**
     * @param list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}> $decisions
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
    public function approveTask(int $taskId, int $userId, array $decisions): array;

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
     *     retry_after?: string
     * }
     */
    public function rejectTask(int $taskId, int $userId, string $reason): array;

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
     *     retry_after?: string
     * }
     */
    public function retryTask(int $taskId, int $userId): array;

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
     *     aborted_at?: string|null
     * }
     */
    /**
     * @param list<string> $mediaIds
     */
    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null, array $mediaIds = []): array;

    /**
     * Abort the running agent loop for the given task. The user-ownership
     * check is enforced here; the state-machine invariant is enforced in
     * {@see \Spora\Agents\Orchestrator::abort()} (RUNNING and
     * AWAITING_SUB_AGENTS sources only).
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
     *     retry_after?: string,
     *     aborted_at?: string|null
     * }
     */
    public function abortTask(int $taskId, int $userId): array;

    /**
     * Abort a sub-agent child task and cascade the abort up the parent
     * chain (every ancestor in AWAITING_SUB_AGENTS gets flipped to
     * ABORTED). Ownership is enforced against the child only.
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
     *     retry_after?: string,
     *     aborted_at?: string|null
     * }
     */
    public function abortSubAgentAndCascade(int $childTaskId, int $userId): array;

    public function deleteTask(int $taskId, int $userId): bool;

    public function cancelRetryChain(int $taskId, int $userId): bool;
}
