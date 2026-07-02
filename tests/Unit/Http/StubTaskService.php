<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use InvalidArgumentException;
use Spora\Services\TaskServiceInterface;

/**
 * Stub TaskServiceInterface that returns canned data for controller tests.
 */
class StubTaskService implements TaskServiceInterface
{
    public ?int $startCalls = 0;
    public ?array $startResult = null;
    public bool $startShouldThrow = false;

    public function getTasksForUser(int $userId, ?int $agentId = null, ?string $since = null, ?int $page = null, ?int $perPage = null): array
    {
        return [
            'tasks' => [
                ['id' => 1, 'agent_id' => 10, 'status' => 'COMPLETED', 'user_prompt' => 'P', 'final_response' => 'R', 'step_count' => 1, 'max_steps' => 10, 'created_at' => null, 'updated_at' => null],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
        ];
    }

    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null): array
    {
        $this->startCalls++;
        if ($this->startShouldThrow) {
            throw new InvalidArgumentException('Agent not found');
        }
        return $this->startResult ?? [
            'id' => 99,
            'agent_id' => $agentId,
            'status' => 'PENDING',
            'user_prompt' => $prompt,
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => $maxSteps ?? 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function getTask(int $taskId, int $userId): ?array
    {
        return null;
    }

    public function getTaskWithHistory(int $taskId, int $userId, ?int $sinceSequence = null): ?array
    {
        if ($taskId === 999999) {
            return null;
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'COMPLETED',
            'user_prompt' => 'p',
            'final_response' => 'r',
            'step_count' => 1,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
            'tool_calls' => [],
            'history' => [],
        ];
    }

    public function approveTask(int $taskId, int $userId, array $approvals): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'APPROVED',
            'user_prompt' => 'p',
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function rejectTask(int $taskId, int $userId, string $reason): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'REJECTED',
            'user_prompt' => 'p',
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function retryTask(int $taskId, int $userId): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => 100,
            'agent_id' => 10,
            'status' => 'PENDING',
            'user_prompt' => 'p',
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null): array
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return [
            'id' => $taskId,
            'agent_id' => 10,
            'status' => 'PENDING',
            'user_prompt' => $prompt,
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 10,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    public function deleteTask(int $taskId, int $userId): bool
    {
        return $taskId !== 999999;
    }

    public function cancelRetryChain(int $taskId, int $userId): bool
    {
        if ($taskId === 999999) {
            throw new InvalidArgumentException('Task not found.');
        }
        return true;
    }
}
