<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;

function makeTaskService(): TaskService
{
    $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
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

    it('orders tasks by updated_at desc (most recently updated first)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('order@example.com', 'Password1!', 'Order');
        simulateLoggedInSession($userId, 'order@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'OrderAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $task1 = Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => 'First', 'max_steps' => 5]);
        Task::where('id', $task1->id)->update(['updated_at' => '2025-01-01 00:00:00']);

        $task2 = Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'RUNNING', 'user_prompt' => 'Second', 'max_steps' => 5]);
        Task::where('id', $task2->id)->update(['updated_at' => '2025-06-01 00:00:00']);

        $task3 = Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'PENDING', 'user_prompt' => 'Third', 'max_steps' => 5]);
        Task::where('id', $task3->id)->update(['updated_at' => '2025-03-01 00:00:00']);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId);

        expect($result)->toHaveCount(3);
        // Most recently updated (task2) should be first
        expect($result[0]['id'])->toBe($task2->id);
        expect($result[1]['id'])->toBe($task3->id);
        expect($result[2]['id'])->toBe($task1->id);
    });

    it('returns paginated results with meta when page is provided', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('paged@example.com', 'Password1!', 'Paged');
        simulateLoggedInSession($userId, 'paged@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'PagedAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        // Create 5 tasks
        for ($i = 1; $i <= 5; $i++) {
            Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => "Task $i", 'max_steps' => 5]);
        }

        $service = makeTaskService();

        // Request page 1 with per_page=2
        $result = $service->getTasksForUser($userId, null, null, 1, 2);

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['tasks', 'meta']);
        expect($result['tasks'])->toHaveCount(2);
        expect($result['meta']['current_page'])->toBe(1);
        expect($result['meta']['last_page'])->toBe(3);
        expect($result['meta']['per_page'])->toBe(2);
        expect($result['meta']['total'])->toBe(5);
    });

    it('returns second page correctly when paginated', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('page2@example.com', 'Password1!', 'Page2');
        simulateLoggedInSession($userId, 'page2@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'Page2Agent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Task::create(['user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => "Task $i", 'max_steps' => 5]);
        }

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId, null, null, 2, 2);

        expect($result['tasks'])->toHaveCount(2);
        expect($result['meta']['current_page'])->toBe(2);
    });
});

describe('TaskService — startTask', function (): void {

    it('creates a task via orchestrator and returns the resource', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('start@example.com', 'Password1!', 'Start');
        simulateLoggedInSession($userId, 'start@example.com');

        $agent = Agent::create([
            'user_id'      => $userId,
            'name'         => 'StartAgent',
            'max_steps'    => 7,
            'is_active'    => true,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        $mercure      = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publish')->once()->andReturn(true);

        $orchestrator->shouldReceive('start')
            ->once()
            ->with($agent->id, 'do the thing', 7, null, null, [])
            ->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps, ?int $parent, ?int $runId, array $mediaIds) use ($userId): Task {
                return Task::create([
                    'user_id'     => $userId,
                    'agent_id'    => $agentId,
                    'status'      => 'RUNNING',
                    'user_prompt' => $prompt,
                    'max_steps'   => $maxSteps,
                    'step_count'  => 0,
                ]);
            });

        $service = new TaskService($orchestrator, $mercure);
        $result  = $service->startTask($userId, $agent->id, 'do the thing');

        expect($result['agent_id'])->toBe($agent->id);
        expect($result['status'])->toBe('RUNNING');
        expect($result['user_prompt'])->toBe('do the thing');
    });

    it('uses agent.max_steps when maxSteps is null', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('start-default@example.com', 'Password1!', 'StartDef');
        simulateLoggedInSession($userId, 'start-default@example.com');

        $agent = Agent::create([
            'user_id'   => $userId,
            'name'      => 'StartDefaultAgent',
            'max_steps' => 12,
            'is_active' => true,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        $mercure      = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publish')->andReturn(true);

        $orchestrator->shouldReceive('start')
            ->once()
            ->with($agent->id, 'p', 12, null, null, []) // 12 = agent.max_steps
            ->andReturnUsing(fn(int $a, string $p, int $m, ?int $parent, ?int $runId, array $mediaIds) => Task::create([
                'user_id' => $userId, 'agent_id' => $a, 'status' => 'RUNNING',
                'user_prompt' => $p, 'max_steps' => $m, 'step_count' => 0,
            ]));

        $service = new TaskService($orchestrator, $mercure);
        $service->startTask($userId, $agent->id, 'p', null);
    });

    it('throws when the agent does not belong to the user', function (): void {
        $authService = bootAuthLayer();
        $userA = $authService->register('ownera@example.com', 'Password1!', 'OwnerA');
        $userB = $authService->register('ownerb@example.com', 'Password1!', 'OwnerB');
        simulateLoggedInSession($userB, 'ownerb@example.com');

        $agentOfA = Agent::create([
            'user_id' => $userA, 'name' => 'A', 'max_steps' => 5, 'is_active' => true,
        ]);

        $service = makeTaskService();
        $service->startTask($userB, $agentOfA->id, 'steal');
    })->throws(InvalidArgumentException::class, 'Agent not found');

    it('throws when the parent task is invalid', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('parentinvalid@example.com', 'Password1!', 'Parent');
        simulateLoggedInSession($userId, 'parentinvalid@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'ParentAgent', 'max_steps' => 5, 'is_active' => true,
        ]);

        $service = makeTaskService();
        $service->startTask($userId, $agent->id, 'continue me', null, 9999);
    })->throws(InvalidArgumentException::class, 'parent_task_id is invalid');
});

describe('TaskService — getTask', function (): void {

    it('returns null when the task does not exist', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('get404@example.com', 'Password1!', 'Get404');
        simulateLoggedInSession($userId, 'get404@example.com');

        $service = makeTaskService();
        expect($service->getTask(9999, $userId))->toBeNull();
    });

    it('returns null when the task belongs to a different user', function (): void {
        $authService = bootAuthLayer();
        $userA = $authService->register('getowna@example.com', 'Password1!', 'A');
        $userB = $authService->register('getownb@example.com', 'Password1!', 'B');

        $agentA = Agent::create([
            'user_id' => $userA, 'name' => 'A', 'max_steps' => 5, 'is_active' => true,
        ]);
        $taskA = Task::create([
            'user_id'     => $userA,
            'agent_id'    => $agentA->id,
            'status'      => 'COMPLETED',
            'user_prompt' => 'private',
            'max_steps'   => 5,
        ]);

        $service = makeTaskService();
        expect($service->getTask($taskA->id, $userB))->toBeNull();
    });

    it('returns the task resource when the task belongs to the user', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('getok@example.com', 'Password1!', 'Get');
        simulateLoggedInSession($userId, 'getok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'GetAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'RUNNING',
            'user_prompt' => 'hi',
            'max_steps'   => 5,
        ]);

        $service = makeTaskService();
        $result  = $service->getTask($task->id, $userId);

        expect($result)->not->toBeNull();
        expect($result['id'])->toBe($task->id);
        expect($result['user_prompt'])->toBe('hi');
    });
});

describe('TaskService — getTaskWithHistory', function (): void {

    it('returns null for a missing task', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('histmiss@example.com', 'Password1!', 'HistMiss');
        simulateLoggedInSession($userId, 'histmiss@example.com');

        $service = makeTaskService();
        expect($service->getTaskWithHistory(9999, $userId))->toBeNull();
    });

    it('returns the task with tool_calls and history arrays', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('histok@example.com', 'Password1!', 'Hist');
        simulateLoggedInSession($userId, 'histok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'HistAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'RUNNING',
            'user_prompt' => 'with history',
            'max_steps'   => 5,
        ]);
        Spora\Models\TaskHistory::create([
            'task_id'  => $task->id,
            'sequence' => 1,
            'role'     => 'user',
            'content'  => 'first',
        ]);
        Spora\Models\TaskHistory::create([
            'task_id'    => $task->id,
            'sequence'   => 2,
            'role'       => 'assistant',
            'content'    => 'response',
            'reasoning'  => 'thinking',
        ]);

        $service = makeTaskService();
        $result  = $service->getTaskWithHistory($task->id, $userId);

        expect($result)->not->toBeNull();
        expect($result['history'])->toBeArray();
        expect($result['history'])->toHaveCount(2);
        expect($result['history'][0]['sequence'])->toBe(1);
        expect($result['history'][1]['reasoning'])->toBe('thinking');
    });

    it('filters history by sinceSequence when provided', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('histsince@example.com', 'Password1!', 'HistSince');
        simulateLoggedInSession($userId, 'histsince@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'HistSinceAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'RUNNING',
            'user_prompt' => 'with history',
            'max_steps'   => 5,
        ]);
        foreach ([1, 2, 3] as $seq) {
            Spora\Models\TaskHistory::create([
                'task_id'  => $task->id,
                'sequence' => $seq,
                'role'     => 'user',
                'content'  => "msg-$seq",
            ]);
        }

        $service = makeTaskService();
        $result  = $service->getTaskWithHistory($task->id, $userId, 1);

        expect($result['history'])->toHaveCount(2);
        expect($result['history'][0]['sequence'])->toBe(2);
        expect($result['history'][1]['sequence'])->toBe(3);
    });

    it('exposes tool_call result_data (e.g. handover new_task_id) in the detail response', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('resultdata@example.com', 'Password1!', 'RD');
        simulateLoggedInSession($userId, 'resultdata@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'ResultDataAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'COMPLETED',
            'user_prompt' => 'handover please',
            'max_steps'   => 5,
        ]);
        Spora\Models\ToolCall::create([
            'task_id'              => $task->id,
            'agent_id'             => $agent->id,
            'provider_call_id'     => 'handover-1',
            'tool_name'            => 'handover',
            'tool_class'           => Spora\Tools\HandoverTool::class,
            'tool_type'            => 'output',
            'operation'            => 'handover',
            'operation_description' => 'Hand over to another agent',
            'status'               => 'EXECUTED',
            'proposed_arguments'   => ['target_agent_id' => 1],
            'approved_arguments'   => ['target_agent_id' => 1],
            'result_content'       => 'Handed over to agent #1. New task #42.',
            'result_data'          => ['new_task_id' => 42, 'handover' => true, 'target_agent_id' => 1],
        ]);

        $service = makeTaskService();
        $result  = $service->getTaskWithHistory($task->id, $userId);

        expect($result)->not->toBeNull();
        expect($result['tool_calls'])->toHaveCount(1);
        expect($result['tool_calls'][0])->toHaveKey('result_data');
        expect($result['tool_calls'][0]['result_data'])->toBe([
            'new_task_id'     => 42,
            'handover'        => true,
            'target_agent_id' => 1,
        ]);
    });
});

describe('TaskService — approveTask', function (): void {

    it('throws when the task is not found', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('approve404@example.com', 'Password1!', 'App404');
        simulateLoggedInSession($userId, 'approve404@example.com');

        $service = makeTaskService();
        $service->approveTask(9999, $userId, []);
    })->throws(InvalidArgumentException::class, 'Task not found');

    it('throws when the task is not in PENDING_APPROVAL status', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('approvebad@example.com', 'Password1!', 'AppBad');
        simulateLoggedInSession($userId, 'approvebad@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'AppBadAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'RUNNING',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $service->approveTask($task->id, $userId, []);
    })->throws(InvalidArgumentException::class, 'not pending approval');

    it('resumes via orchestrator and returns the updated task', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('approveok@example.com', 'Password1!', 'AppOk');
        simulateLoggedInSession($userId, 'approveok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'AppOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'PENDING_APPROVAL',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        $mercure      = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publish')->once()->andReturn(true);

        $orchestrator->shouldReceive('resume')
            ->once()
            ->with($task->id, [['provider_call_id' => 'c1', 'arguments' => ['x' => 1]]])
            ->andReturnUsing(function () use ($task): void {
                Task::where('id', $task->id)->update(['status' => 'RUNNING']);
            });

        $service = new TaskService($orchestrator, $mercure);
        $result  = $service->approveTask($task->id, $userId, [
            ['provider_call_id' => 'c1', 'arguments' => ['x' => 1]],
        ]);

        expect($result['status'])->toBe('RUNNING');
    });
});

describe('TaskService — rejectTask', function (): void {

    it('throws when the task is not found', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('rej404@example.com', 'Password1!', 'Rej404');
        simulateLoggedInSession($userId, 'rej404@example.com');

        $service = makeTaskService();
        $service->rejectTask(9999, $userId, 'nope');
    })->throws(InvalidArgumentException::class, 'Task not found');

    it('throws when the task is not in PENDING_APPROVAL status', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('rejbad@example.com', 'Password1!', 'RejBad');
        simulateLoggedInSession($userId, 'rejbad@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'RejBadAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $service->rejectTask($task->id, $userId, 'too late');
    })->throws(InvalidArgumentException::class, 'not pending approval');

    it('rejects via orchestrator and returns the updated task', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('rejok@example.com', 'Password1!', 'RejOk');
        simulateLoggedInSession($userId, 'rejok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'RejOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'PENDING_APPROVAL',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        $mercure      = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publish')->once()->andReturn(true);

        $orchestrator->shouldReceive('reject')
            ->once()
            ->with($task->id, 'unsafe')
            ->andReturnUsing(function () use ($task): void {
                Task::where('id', $task->id)->update(['status' => 'FAILED']);
            });

        $service = new TaskService($orchestrator, $mercure);
        $result  = $service->rejectTask($task->id, $userId, 'unsafe');

        expect($result['status'])->toBe('FAILED');
    });
});

describe('TaskService — retryTask', function (): void {

    it('throws when the task is not found', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('retry404@example.com', 'Password1!', 'Ret404');
        simulateLoggedInSession($userId, 'retry404@example.com');

        $service = makeTaskService();
        $service->retryTask(9999, $userId);
    })->throws(InvalidArgumentException::class, 'Task not found');

    it('throws when the task is not in FAILED status', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('retrybad@example.com', 'Password1!', 'RetBad');
        simulateLoggedInSession($userId, 'retrybad@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'RetBadAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $service->retryTask($task->id, $userId);
    })->throws(InvalidArgumentException::class, 'Only failed tasks can be retried');

    it('creates a new task via orchestrator and publishes it', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('retryok@example.com', 'Password1!', 'RetOk');
        simulateLoggedInSession($userId, 'retryok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'RetOkAgent', 'max_steps' => 8, 'is_active' => true,
        ]);
        $original = Task::create([
            'user_id'     => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'FAILED',
            'user_prompt' => 'please try again',
            'max_steps'   => 8,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        $mercure      = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publish')->once()->andReturn(true);

        $orchestrator->shouldReceive('start')
            ->once()
            ->with($agent->id, 'please try again', 8, null, null, [])
            ->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps, ?int $parent, ?int $runId, array $mediaIds) use ($userId): Task {
                return Task::create([
                    'user_id'     => $userId,
                    'agent_id'    => $agentId,
                    'status'      => 'RUNNING',
                    'user_prompt' => $prompt,
                    'max_steps'   => $maxSteps,
                    'step_count'  => 0,
                ]);
            });

        $service = new TaskService($orchestrator, $mercure);
        $result  = $service->retryTask($original->id, $userId);

        expect($result['user_prompt'])->toBe('please try again');
        expect($result['status'])->toBe('RUNNING');
        expect($result['id'])->not->toBe($original->id);
    });
});

describe('TaskService — continueTask', function (): void {

    it('throws when the task is not found', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('cont404@example.com', 'Password1!', 'Cont404');
        simulateLoggedInSession($userId, 'cont404@example.com');

        $service = makeTaskService();
        $service->continueTask(9999, $userId, 'keep going');
    })->throws(InvalidArgumentException::class, 'Task not found');

    it('throws when the task is in a non-terminal status', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('contrun@example.com', 'Password1!', 'ContRun');
        simulateLoggedInSession($userId, 'contrun@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'ContRunAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'RUNNING',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $service->continueTask($task->id, $userId, 'next');
    })->throws(InvalidArgumentException::class, 'Can only continue completed or failed tasks');

    it('throws when additional_steps is out of bounds', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('contbadsteps@example.com', 'Password1!', 'ContSteps');
        simulateLoggedInSession($userId, 'contbadsteps@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'ContStepsAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $service->continueTask($task->id, $userId, 'more', 0);
    })->throws(InvalidArgumentException::class, 'additional_steps must be an integer between 1 and 100');

    it('continues a completed task via orchestrator', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('contok@example.com', 'Password1!', 'ContOk');
        simulateLoggedInSession($userId, 'contok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'ContOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        $mercure      = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publish')->once()->andReturn(true);

        $orchestrator->shouldReceive('continue')
            ->once()
            ->with($task->id, 'more please', 10, [])
            ->andReturnUsing(function (int $taskId, string $prompt, ?int $steps, array $mediaIds) use ($userId): Task {
                return Task::create([
                    'user_id'     => $userId,
                    'agent_id'    => 1,
                    'status'      => 'RUNNING',
                    'user_prompt' => 'more please',
                    'max_steps'   => 10,
                    'step_count'  => 0,
                ]);
            });

        $service = new TaskService($orchestrator, $mercure);
        $result  = $service->continueTask($task->id, $userId, 'more please', 10);

        expect($result['user_prompt'])->toBe('more please');
        expect($result['status'])->toBe('RUNNING');
    });
});

describe('TaskService — deleteTask', function (): void {

    it('returns false when the task does not exist', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('del404@example.com', 'Password1!', 'Del404');
        simulateLoggedInSession($userId, 'del404@example.com');

        $service = makeTaskService();
        expect($service->deleteTask(9999, $userId))->toBeFalse();
    });

    it('deletes a leaf task and its history/tool_calls', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('delok@example.com', 'Password1!', 'Del');
        simulateLoggedInSession($userId, 'delok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'DelAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);
        Spora\Models\TaskHistory::create([
            'task_id' => $task->id, 'sequence' => 1, 'role' => 'user', 'content' => 'x',
        ]);

        $service = makeTaskService();
        expect($service->deleteTask($task->id, $userId))->toBeTrue();
        expect(Task::find($task->id))->toBeNull();
        expect(Spora\Models\TaskHistory::where('task_id', $task->id)->count())->toBe(0);
    });

    it('cascades delete to retry children when the task is a parent', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('delparent@example.com', 'Password1!', 'DelPar');
        simulateLoggedInSession($userId, 'delparent@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'DelParAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $parent = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'orig', 'max_steps' => 5,
        ]);
        $child = Task::create([
            'user_id'        => $userId,
            'agent_id'       => $agent->id,
            'status'         => 'QUEUED',
            'user_prompt'    => 'retry',
            'max_steps'      => 5,
            'retry_of_task_id' => $parent->id,
        ]);

        $service = makeTaskService();
        expect($service->deleteTask($parent->id, $userId))->toBeTrue();
        expect(Task::find($parent->id))->toBeNull();
        expect(Task::find($child->id))->toBeNull();
    });

    it('does not delete other tasks that point to a different retry parent', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('delnonparent@example.com', 'Password1!', 'DelNP');
        simulateLoggedInSession($userId, 'delnonparent@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'DelNPAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $parent = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'orig', 'max_steps' => 5,
        ]);
        $childOfOther = Task::create([
            'user_id'        => $userId,
            'agent_id'       => $agent->id,
            'status'         => 'QUEUED',
            'user_prompt'    => 'unrelated retry',
            'max_steps'      => 5,
            'retry_of_task_id' => $parent->id + 1,
        ]);

        $service = makeTaskService();
        $service->deleteTask($parent->id, $userId);

        expect(Task::find($childOfOther->id))->not->toBeNull();
    });
});

describe('TaskService — cancelRetryChain', function (): void {

    it('returns false when the task does not exist', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('cancel404@example.com', 'Password1!', 'Cancel404');
        simulateLoggedInSession($userId, 'cancel404@example.com');

        $service = makeTaskService();
        expect($service->cancelRetryChain(9999, $userId))->toBeFalse();
    });

    it('throws when the task is not part of a retry chain', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('cancelnoretry@example.com', 'Password1!', 'CancelNR');
        simulateLoggedInSession($userId, 'cancelnoretry@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'CancelNRAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'p', 'max_steps' => 5, 'retry_of_task_id' => null,
        ]);

        $service = makeTaskService();
        $service->cancelRetryChain($task->id, $userId);
    })->throws(InvalidArgumentException::class, 'not part of a retry chain');

    it('cancels the retry task and all sibling retries at the same level or later', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('cancelok@example.com', 'Password1!', 'CancelOk');
        simulateLoggedInSession($userId, 'cancelok@example.com');

        $agent = Agent::create([
            'user_id' => $userId, 'name' => 'CancelOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $root = Task::create([
            'user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'orig', 'max_steps' => 5,
        ]);
        $retry1 = Task::create([
            'user_id'        => $userId,
            'agent_id'       => $agent->id,
            'status'         => 'QUEUED',
            'user_prompt'    => 'r1',
            'max_steps'      => 5,
            'retry_of_task_id' => $root->id,
            'retry_count'    => 1,
        ]);
        $retry2 = Task::create([
            'user_id'        => $userId,
            'agent_id'       => $agent->id,
            'status'         => 'QUEUED',
            'user_prompt'    => 'r2',
            'max_steps'      => 5,
            'retry_of_task_id' => $root->id,
            'retry_count'    => 2,
        ]);

        $service = makeTaskService();
        expect($service->cancelRetryChain($retry1->id, $userId))->toBeTrue();

        $retry1->refresh();
        $retry2->refresh();
        expect($retry1->status)->toBe('CANCELLED');
        expect($retry2->status)->toBe('CANCELLED');
        // The original root is not part of the cancelled chain
        expect($root->fresh()->status)->toBe('FAILED');
    });
});
