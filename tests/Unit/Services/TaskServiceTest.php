<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;

function makeTaskService(): TaskService
{
    $orchestrator = Mockery::mock(\Spora\Agents\OrchestratorInterface::class);
    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);
    $mercure->allows('publishToUser')->andReturn(true);

    return new TaskService($orchestrator, $mercure);
}

describe('TaskService — getTasksForUser', function (): void {

    it('returns tasks without tool_calls and history keys', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('list@example.com', 'Password1!', 'List');
        simulateLoggedInSession($userId, 'list@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'ListTestAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'max_retries'  => 3,
            'retry_after_minutes' => 10,
            'is_active'    => true,
        ]);

        $task = Task::create([
            'user_id'   => $userId,
            'agent_id'  => $agent->id,
            'status'    => 'COMPLETED',
            'user_prompt' => 'Test prompt',
            'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId);

        expect($result)->toHaveCount(1);
        $taskData = $result[0];
        expect(array_key_exists('tool_calls', $taskData))->toBe(false);
        expect(array_key_exists('history', $taskData))->toBe(false);
        expect($taskData['id'])->toBe($task->id);
        expect($taskData['status'])->toBe('COMPLETED');
        expect($taskData['user_prompt'])->toBe('Test prompt');
    });

    it('returns max_retries and retry_after_minutes from eager-loaded agent', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('agentrel@example.com', 'Password1!', 'Agentrel');
        simulateLoggedInSession($userId, 'agentrel@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'AgentRelAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'max_retries'  => 7,
            'retry_after_minutes' => 15,
            'is_active'    => true,
        ]);

        Task::create([
            'user_id'   => $userId,
            'agent_id'  => $agent->id,
            'status'    => 'RUNNING',
            'user_prompt' => 'Run me',
            'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId);

        expect($result)->toHaveCount(1);
        expect($result[0]['max_retries'])->toBe(7);
        expect($result[0]['retry_after_minutes'])->toBe(15);
    });

    it('filters by agent_id when provided', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('filter@example.com', 'Password1!', 'Filter');
        simulateLoggedInSession($userId, 'filter@example.com');

        $agent1 = Agent::create([
            'user_id' => $userId, 'name' => 'Agent1',
            'llm_provider' => 'mock', 'llm_model' => 'mock',
            'max_steps' => 5, 'is_active' => true,
        ]);
        $agent2 = Agent::create([
            'user_id' => $userId, 'name' => 'Agent2',
            'llm_provider' => 'mock', 'llm_model' => 'mock',
            'max_steps' => 5, 'is_active' => true,
        ]);

        Task::create(['user_id' => $userId, 'agent_id' => $agent1->id, 'status' => 'COMPLETED', 'user_prompt' => 'A1', 'max_steps' => 5]);
        Task::create(['user_id' => $userId, 'agent_id' => $agent2->id, 'status' => 'RUNNING', 'user_prompt' => 'A2', 'max_steps' => 5]);

        $service = makeTaskService();

        $all = $service->getTasksForUser($userId);
        expect($all)->toHaveCount(2);

        $filtered = $service->getTasksForUser($userId, $agent1->id);
        expect($filtered)->toHaveCount(1);
        expect($filtered[0]['agent_id'])->toBe($agent1->id);
    });

    it('filters by updated_at when since is provided', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('since@example.com', 'Password1!', 'Since');
        simulateLoggedInSession($userId, 'since@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'SinceAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $oldTask = Task::create([
            'user_id'   => $userId,
            'agent_id'  => $agent->id,
            'status'    => 'COMPLETED',
            'user_prompt' => 'Old task',
            'max_steps' => 5,
        ]);
        // Manually set updated_at to a past time
        Task::where('id', $oldTask->id)->update(['updated_at' => '2024-01-01 00:00:00']);

        $newTask = Task::create([
            'user_id'   => $userId,
            'agent_id'  => $agent->id,
            'status'    => 'RUNNING',
            'user_prompt' => 'New task',
            'max_steps' => 5,
        ]);
        // Manually set updated_at to a recent time
        Task::where('id', $newTask->id)->update(['updated_at' => '2025-06-01 00:00:00']);

        $service = makeTaskService();

        // Without since, both tasks are returned
        $all = $service->getTasksForUser($userId);
        expect($all)->toHaveCount(2);

        // With since after old task but before new task, only new task is returned
        $since = '2024-06-01T00:00:00Z';
        $filtered = $service->getTasksForUser($userId, null, $since);
        expect($filtered)->toHaveCount(1);
        expect($filtered[0]['id'])->toBe($newTask->id);
    });

    it('returns all tasks when since is not provided (backward compatible)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('nocsince@example.com', 'Password1!', 'Nocsince');
        simulateLoggedInSession($userId, 'nocsince@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'NoSinceAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => 'Task 1', 'max_steps' => 5]);
        Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'RUNNING', 'user_prompt' => 'Task 2', 'max_steps' => 5]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId);

        expect($result)->toHaveCount(2);
    });

    it('returns empty array when since filter excludes all tasks (no crash)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('futuresince@example.com', 'Password1!', 'Futuresince');
        simulateLoggedInSession($userId, 'futuresince@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'FutureSinceAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => 'Task', 'max_steps' => 5]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId, null, '2099-01-01T00:00:00Z');

        expect($result)->toBeEmpty();
    });
});
