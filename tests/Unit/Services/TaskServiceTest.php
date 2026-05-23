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
        $userId = $authService->register('list@example.com', 'Password1!');
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
        $userId = $authService->register('agentrel@example.com', 'Password1!');
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
        $userId = $authService->register('filter@example.com', 'Password1!');
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
});
