<?php

declare(strict_types=1);

namespace Spora\Services;

interface TaskServiceInterface
{
    /**
     * @return array<array{
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
    public function getTasksForUser(int $userId, ?int $agentId = null): array;

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
    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null): array;

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
     *     tool_calls: list<array{
     *         id: int,
     *         tool_name: string,
     *         tool_type: string,
     *         status: string,
     *         proposed_arguments: array|null,
     *         approved_arguments: array|null,
     *         human_description: string|null,
     *         result_content: string|null,
     *         executed_at: string|null
     *     }>,
     *     history: list<array{
     *         sequence: int,
     *         role: string,
     *         content: string|null,
     *         reasoning: string|null,
     *         tool_call_id: string|null,
     *         tool_name: string|null
     *     }>
     * }|null
     */
    public function getTaskWithHistory(int $taskId, int $userId, ?int $sinceSequence = null): ?array;

    /**
     * @param list<array{provider_call_id: string, arguments: array<string, mixed>}> $approvals
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
    public function approveTask(int $taskId, int $userId, array $approvals): array;

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
     *     retry_after?: string
     * }
     */
    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null): array;

    public function deleteTask(int $taskId, int $userId): bool;

    public function cancelRetryChain(int $taskId, int $userId): bool;
}