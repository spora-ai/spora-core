<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskService;

function makeTaskService(): TaskService
{
    $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);
    $mercure->allows('publishToUser')->andReturn(true);

    return new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
}

function ensureAgentsHasPrincipalId(int $agentId, int $userId): void
{
    $principalId = Spora\Models\Principal::where('type', 'user')
        ->where('user_id', $userId)
        ->value('id');
    if ($principalId === null) {
        $principalId = Capsule::table('principals')->insertGetId([
            'type'       => 'user',
            'user_id'    => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    Capsule::table('agents')->where('id', $agentId)
        ->update(['principal_id' => (int) $principalId]);
}

describe('TaskService — getTasksForUser', function (): void {

    it('returns tasks without tool_calls and history keys', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('list@example.com', 'Password1!', 'List');
        simulateLoggedInSession($userId, 'list@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'ListTestAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'max_retries'  => 3,
            'retry_after_minutes' => 10,
            'is_active'    => true,
        ]);

        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'AgentRelAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'max_retries'  => 7,
            'retry_after_minutes' => 15,
            'is_active'    => true,
        ]);

        Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'Agent1',
            'llm_provider' => 'mock', 'llm_model' => 'mock',
            'max_steps' => 5, 'is_active' => true,
        ]);
        $agent2 = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'Agent2',
            'llm_provider' => 'mock', 'llm_model' => 'mock',
            'max_steps' => 5, 'is_active' => true,
        ]);

        Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent1->id, 'status' => 'COMPLETED', 'user_prompt' => 'A1', 'max_steps' => 5]);
        Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent2->id, 'status' => 'RUNNING', 'user_prompt' => 'A2', 'max_steps' => 5]);

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
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'SinceAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $oldTask = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'  => $agent->id,
            'status'    => 'COMPLETED',
            'user_prompt' => 'Old task',
            'max_steps' => 5,
        ]);
        // Manually set updated_at to a past time
        Task::where('id', $oldTask->id)->update(['updated_at' => '2024-01-01 00:00:00']);

        $newTask = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'NoSinceAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => 'Task 1', 'max_steps' => 5]);
        Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'RUNNING', 'user_prompt' => 'Task 2', 'max_steps' => 5]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId);

        expect($result)->toHaveCount(2);
    });

    it('returns empty array when since filter excludes all tasks (no crash)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('futuresince@example.com', 'Password1!', 'Futuresince');
        simulateLoggedInSession($userId, 'futuresince@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'FutureSinceAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => 'Task', 'max_steps' => 5]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId, null, '2099-01-01T00:00:00Z');

        expect($result)->toBeEmpty();
    });

    it('orders tasks by updated_at desc (most recently updated first)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('order@example.com', 'Password1!', 'Order');
        simulateLoggedInSession($userId, 'order@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'OrderAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $task1 = Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => 'First', 'max_steps' => 5]);
        Task::where('id', $task1->id)->update(['updated_at' => '2025-01-01 00:00:00']);

        $task2 = Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'RUNNING', 'user_prompt' => 'Second', 'max_steps' => 5]);
        Task::where('id', $task2->id)->update(['updated_at' => '2025-06-01 00:00:00']);

        $task3 = Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'PENDING', 'user_prompt' => 'Third', 'max_steps' => 5]);
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
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'PagedAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        // Create 5 tasks
        for ($i = 1; $i <= 5; $i++) {
            Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => "Task $i", 'max_steps' => 5]);
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
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'Page2Agent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Task::create(['principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED', 'user_prompt' => "Task $i", 'max_steps' => 5]);
        }

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId, null, null, 2, 2);

        expect($result['tasks'])->toHaveCount(2);
        expect($result['meta']['current_page'])->toBe(2);
    });

    it('filters by status when status is provided', function (): void {
        // Powers GET /api/v1/tasks?status=QUEUED. Three rows in distinct
        // statuses; only the matching one comes back.
        $authService = bootAuthLayer();
        $userId = $authService->register('statusfilter@example.com', 'Password1!', 'StatusFilter');
        simulateLoggedInSession($userId, 'statusfilter@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'StatusFilterAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        $queued = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'     => $agent->id,
            'status'       => 'QUEUED',
            'user_prompt'  => 'queued task',
            'max_steps'    => 5,
        ]);
        Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'     => $agent->id,
            'status'       => 'RUNNING',
            'user_prompt'  => 'running task',
            'max_steps'    => 5,
        ]);
        Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'     => $agent->id,
            'status'       => 'COMPLETED',
            'user_prompt'  => 'completed task',
            'max_steps'    => 5,
        ]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId, null, null, null, null, 'QUEUED');

        expect($result)->toHaveCount(1);
        expect($result[0]['id'])->toBe($queued->id)
            ->and($result[0]['status'])->toBe('QUEUED');
    });

    it('treats an empty status string the same as null (no filter)', function (): void {
        // The controller guards `$status !== ''` before passing it down,
        // so an empty string at the service boundary means "no filter" —
        // we shouldn't add a `where('status', '')` clause that returns
        // zero rows.
        $authService = bootAuthLayer();
        $userId = $authService->register('statusempty@example.com', 'Password1!', 'StatusEmpty');
        simulateLoggedInSession($userId, 'statusempty@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'StatusEmptyAgent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'     => $agent->id,
            'status'       => 'QUEUED',
            'user_prompt'  => 'q',
            'max_steps'    => 5,
        ]);
        Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'     => $agent->id,
            'status'       => 'RUNNING',
            'user_prompt'  => 'r',
            'max_steps'    => 5,
        ]);

        $service = makeTaskService();
        $result = $service->getTasksForUser($userId, null, null, null, null, '');

        expect($result)->toHaveCount(2);
    });
});

describe('TaskService — startTask', function (): void {

    it('creates a task via orchestrator and returns the resource', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('start@example.com', 'Password1!', 'Start');
        simulateLoggedInSession($userId, 'start@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'StartAgent',
            'max_steps'    => 7,
            'is_active'    => true,
        ]);
        ensureAgentsHasPrincipalId($agent->id, $userId);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publishForPrincipal')->once()->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $orchestrator->shouldReceive('start')
            ->once()
            ->with($agent->id, 'do the thing', 7, null, null, [], $userId)
            ->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps, ?int $parent, ?int $runId, array $mediaIds, ?int $userIdArg = null) use ($userId): Task {
                return Task::create([
                    'principal_id' => createUserPrincipalPublic($userId),
                    'trigger_user_id' => $userIdArg ?? $userId,
                    'agent_id'    => $agentId,
                    'status'      => 'RUNNING',
                    'user_prompt' => $prompt,
                    'max_steps'   => $maxSteps,
                    'step_count'  => 0,
                ]);
            });

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
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
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'StartDefaultAgent',
            'max_steps' => 12,
            'is_active' => true,
        ]);
        ensureAgentsHasPrincipalId($agent->id, $userId);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publish')->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $orchestrator->shouldReceive('start')
            ->once()
            ->with($agent->id, 'p', 12, null, null, [], $userId) // 12 = agent.max_steps
            ->andReturnUsing(fn(int $a, string $p, int $m, ?int $parent, ?int $runId, array $mediaIds, ?int $userIdArg = null) => Task::create([
                'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userIdArg ?? $userId, 'agent_id' => $a, 'status' => 'RUNNING',
                'user_prompt' => $p, 'max_steps' => $m, 'step_count' => 0,
            ]));

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
        $service->startTask($userId, $agent->id, 'p', null);
    });

    it('throws when the agent does not belong to the user', function (): void {
        $authService = bootAuthLayer();
        $userA = $authService->register('ownera@example.com', 'Password1!', 'OwnerA');
        $userB = $authService->register('ownerb@example.com', 'Password1!', 'OwnerB');
        simulateLoggedInSession($userB, 'ownerb@example.com');

        $agentOfA = Agent::create([
            'principal_id' => createUserPrincipalPublic($userA), 'name' => 'A', 'max_steps' => 5, 'is_active' => true,
        ]);

        $service = makeTaskService();
        $service->startTask($userB, $agentOfA->id, 'steal');
    })->throws(InvalidArgumentException::class, 'Agent not found');

    it('throws when the parent task is invalid', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('parentinvalid@example.com', 'Password1!', 'Parent');
        simulateLoggedInSession($userId, 'parentinvalid@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'ParentAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        ensureAgentsHasPrincipalId($agent->id, $userId);

        $service = makeTaskService();
        $service->startTask($userId, $agent->id, 'continue me', null, 9999);
    })->throws(InvalidArgumentException::class, 'parent_task_id is invalid');

    it('passes the caller userId to the orchestrator so the task row is attributed to the caller, not the owner (regression for stale-cache-group bug)', function (): void {
        // Group setup: owner + plain member share a group-owned agent.
        $authService = bootAuthLayer();
        $ownerId         = $authService->register('task-owner@example.com', 'Password1!', 'Owner');
        $plainMemberId   = $authService->register('task-plain@example.com', 'Password1!', 'Plain');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);

        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'TaskAttribution');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);

        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;
        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'GroupAgent',
            'max_steps'    => 10,
            'is_active'    => true,
        ]);

        // Orchestrator passes user_id through to the row, just like the real
        // implementation does (Task::create([..., 'trigger_user_id' => $resolvedUserId])).
        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publish')->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $orchestrator->shouldReceive('start')
            ->once()
            ->with($agent->id, 'plain member chat', 10, null, null, [], $plainMemberId)
            ->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps, ?int $parent, ?int $runId, array $mediaIds, ?int $userIdArg = null): Task {
                return Task::create([
                    'agent_id'    => $agentId,
                    'principal_id' => (int) Agent::find($agentId)->principal_id,
                    'trigger_user_id' => $userIdArg ?? 0,
                    'status'      => 'RUNNING',
                    'user_prompt' => $prompt,
                    'max_steps'   => $maxSteps,
                    'step_count'  => 0,
                ]);
            });

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
        $service->startTask($plainMemberId, $agent->id, 'plain member chat');

        // The task row must be attributed to the caller, not the owner.
        // Post-0071: `trigger_user_id` carries the clicker identity, NOT
        // `user_id` (which no longer exists on `tasks`).
        $taskRow = Task::where('agent_id', $agent->id)->orderByDesc('id')->first();
        expect($taskRow)->not->toBeNull();
        expect((int) $taskRow->trigger_user_id)->toBe($plainMemberId)
            ->and((int) $taskRow->trigger_user_id)->not->toBe($ownerId);
    });

    it('falls back to PrincipalResolver when no explicit userId is passed (worker / scheduled-run path preserves prior behaviour)', function (): void {
        // Worker / scheduled-run callers don't know the caller id; orchestrator
        // resolves `runnerUserId` from the agent's last task. We pin that the
        // existing default is preserved when the orchestrator is called with
        // `userId = null`.
        $authService = bootAuthLayer();
        $userId = $authService->register('task-null-fallback@example.com', 'Password1!', 'Worker');
        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'         => 'WorkerAgent',
            'max_steps'    => 4,
            'is_active'    => true,
        ]);
        ensureAgentsHasPrincipalId($agent->id, $userId);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publish')->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $orchestrator->shouldReceive('start')
            ->once()
            ->with($agent->id, 'worker chat', 4, null, null, [], null)
            ->andReturnUsing(fn($a, $p, $m) => Task::create([
                'agent_id' => $a, 'principal_id' => (int) Agent::find($a)->principal_id,
                'trigger_user_id' => $userId, 'status' => 'RUNNING',
                'user_prompt' => $p, 'max_steps' => $m, 'step_count' => 0,
            ]));

        // Simulate the worker / scheduled-run path by calling orchestrator.start() directly.
        $orchestrator->start($agent->id, 'worker chat', 4, null, null, [], null);
    });
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
            'principal_id' => createUserPrincipalPublic($userA), 'name' => 'A', 'max_steps' => 5, 'is_active' => true,
        ]);
        $taskA = Task::create([
            'principal_id' => createUserPrincipalPublic($userA),
            'trigger_user_id' => $userA,
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'GetAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'HistAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
        ]);

        $service = makeTaskService();
        $result  = $service->getTaskWithHistory($task->id, $userId);

        expect($result)->not->toBeNull();
        expect($result['history'])->toBeArray();
        expect($result['history'])->toHaveCount(2);
        expect($result['history'][0]['sequence'])->toBe(1);
        // The legacy `reasoning` column was dropped from `task_history` when
        // the `displayReasoning` round-trip was removed — reasoning is now
        // reachable only through the structured `content_blocks[]` of
        // `type === "thinking"`. The wire payload must therefore expose no
        // `reasoning` key at all. `content_blocks` is empty here because
        // this fixture pre-dates the structured-block persistence path;
        // `usage` is omitted because no usage row was persisted alongside
        // this history row.
        expect($result['history'][1])->not->toHaveKey('reasoning');
        expect($result['history'][1])->toHaveKey('content_blocks');
        expect($result['history'][1]['content_blocks'])->toBe([]);
    });

    it('filters history by sinceSequence when provided', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('histsince@example.com', 'Password1!', 'HistSince');
        simulateLoggedInSession($userId, 'histsince@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'HistSinceAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'ResultDataAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'operation_description' => 'Hand over a task to the target agent',
            'status'               => 'EXECUTED',
            'proposed_arguments'   => ['target_agent_id' => 1],
            'approved_arguments'   => ['target_agent_id' => 1],
            'result_content'       => 'Task delegated to agent #1. [New task #42](/tasks/42).',
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

    it('emits Shape A fields (operation, operation_description, parameter_schema) on getTaskWithHistory', function (): void {
        // getTaskWithHistory used to skip the ToolCallSerializer path and
        // emit a slimmer shape (no operation, operation_description, or
        // parameter_schema). The chat UI now renders tool input panels, so
        // those fields have to be on this endpoint too. This test pins
        // parity with the Mercure live stream and the approve/reject/retry
        // resource shapes.
        $authService = bootAuthLayer();
        $userId = $authService->register('shapeparity@example.com', 'Password1!', 'ShapeParity');
        simulateLoggedInSession($userId, 'shapeparity@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'ShapeParityAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'COMPLETED',
            'user_prompt' => 'shape parity',
            'max_steps'   => 5,
        ]);
        Spora\Models\ToolCall::create([
            'task_id'               => $task->id,
            'agent_id'              => $agent->id,
            'provider_call_id'      => 'shape-1',
            'tool_name'             => 'serializer_fixture',
            'tool_class'            => Tests\Unit\Services\ToolCallSerializerFixtureTool::class,
            'tool_type'             => 'output',
            'operation'             => 'run',
            'operation_description' => 'Run',
            'status'                => 'EXECUTED',
            'proposed_arguments'    => ['q' => 'hello'],
            'approved_arguments'    => ['q' => 'hello'],
            'result_content'        => 'ok',
        ]);

        $service = makeTaskService();
        $result  = $service->getTaskWithHistory($task->id, $userId);

        expect($result)->not->toBeNull();
        $tc = $result['tool_calls'][0];
        expect($tc)->toHaveKeys(['operation', 'operation_description', 'parameter_schema']);
        expect($tc['operation'])->toBe('run');
        expect($tc['operation_description'])->toBe('Run');
        expect($tc['parameter_schema'])->not->toBeNull();
        expect(array_keys($tc['parameter_schema']['properties']))->toBe(['action', 'q']);
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'AppBadAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'RUNNING',
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'AppOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'PENDING_APPROVAL',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publishForPrincipal')->once()->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $orchestrator->shouldReceive('resume')
            ->once()
            ->with($task->id, [['provider_call_id' => 'c1', 'decision' => 'approve', 'arguments' => ['x' => 1]]])
            ->andReturnUsing(function () use ($task): void {
                Task::where('id', $task->id)->update(['status' => 'RUNNING']);
            });

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
        $result  = $service->approveTask($task->id, $userId, [
            ['provider_call_id' => 'c1', 'decision' => 'approve', 'arguments' => ['x' => 1]],
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'RejBadAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'RejOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'PENDING_APPROVAL',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publishForPrincipal')->once()->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $orchestrator->shouldReceive('reject')
            ->once()
            ->with($task->id, 'unsafe')
            ->andReturnUsing(function () use ($task): void {
                Task::where('id', $task->id)->update(['status' => 'FAILED']);
            });

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'RetBadAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $service->retryTask($task->id, $userId);
    })->throws(InvalidArgumentException::class, 'Only failed tasks can be retried');

    it('re-runs the failed task in place via orchestrator and publishes it', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('retryok@example.com', 'Password1!', 'RetOk');
        simulateLoggedInSession($userId, 'retryok@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'RetOkAgent', 'max_steps' => 8, 'is_active' => true,
        ]);
        $original = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'FAILED',
            'user_prompt' => 'please try again',
            'max_steps'   => 8,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publishForPrincipal')->once()->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        // Orchestrator::retry() resets the failed task in place and returns
        // the same task. The mock simulates the reset without actually running
        // the LLM.
        $orchestrator->shouldReceive('retry')
            ->once()
            ->with($original->id)
            ->andReturnUsing(function (int $taskId) use ($original): Task {
                $original->status = 'RUNNING';
                $original->step_count = 0;
                $original->error_code = null;
                $original->failure_reason = null;
                $original->retry_after = null;
                $original->save();
                return $original->fresh();
            });

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
        $result  = $service->retryTask($original->id, $userId);

        expect($result['user_prompt'])->toBe('please try again');
        expect($result['status'])->toBe('RUNNING');
        expect($result['id'])->toBe($original->id);
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

    it('throws when the task is in a non-resumable status (PENDING_APPROVAL)', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('contpa@example.com', 'Password1!', 'ContPa');
        simulateLoggedInSession($userId, 'contpa@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'ContPaAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'PENDING_APPROVAL',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        $service->continueTask($task->id, $userId, 'next');
    })->throws(InvalidArgumentException::class, 'Can only continue completed, failed, aborted, or running tasks');

    it('throws when additional_steps is out of bounds', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('contbadsteps@example.com', 'Password1!', 'ContSteps');
        simulateLoggedInSession($userId, 'contbadsteps@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'ContStepsAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'ContOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
            'user_prompt' => 'p', 'max_steps' => 5,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publishForPrincipal')->once()->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $orchestrator->shouldReceive('continue')
            ->once()
            ->with($task->id, 'more please', 10, [])
            ->andReturnUsing(function (int $taskId, string $prompt, ?int $steps, array $mediaIds) use ($userId): Task {
                return Task::create([
                    'principal_id' => createUserPrincipalPublic($userId),
                    'trigger_user_id' => $userId,
                    'agent_id'    => 1,
                    'status'      => 'RUNNING',
                    'user_prompt' => 'more please',
                    'max_steps'   => 10,
                    'step_count'  => 0,
                ]);
            });

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'DelAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'COMPLETED',
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'DelParAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $parent = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'orig', 'max_steps' => 5,
        ]);
        $child = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'DelNPAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $parent = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'orig', 'max_steps' => 5,
        ]);
        $childOfOther = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'CancelNRAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        $task = Task::create([
            'principal_id' => createUserPrincipalPublic($userId), 'trigger_user_id' => $userId, 'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'p', 'max_steps' => 5, 'retry_of_task_id' => null,
        ]);

        $service = makeTaskService();
        $service->cancelRetryChain($task->id, $userId);
    })->throws(InvalidArgumentException::class, 'not part of a retry chain');

    it('clears retry_after on the failed task in the chain so the worker stops re-ticking it', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('cancelok@example.com', 'Password1!', 'CancelOk');
        simulateLoggedInSession($userId, 'cancelok@example.com');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'CancelOkAgent', 'max_steps' => 5, 'is_active' => true,
        ]);
        // In-place retry: the failed task itself is the chain head. The
        // chain "member" is the same row, with retry_of_task_id pointing to
        // itself and retry_after set in the future.
        $failed = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'    => $agent->id,
            'status'      => 'FAILED',
            'user_prompt' => 'orig',
            'max_steps'   => 5,
            'retry_count' => 1,
        ]);
        $failed->retry_of_task_id = $failed->id;
        $failed->save();
        Capsule::table('tasks')
            ->where('id', $failed->id)
            ->update(['retry_after' => date('Y-m-d H:i:s', time() + 600)]);

        $service = makeTaskService();
        expect($service->cancelRetryChain($failed->id, $userId))->toBeTrue();

        $failed->refresh();
        // Status stays FAILED so the user can still see the failure.
        expect($failed->status)->toBe('FAILED');
        // retry_after and retry_of_task_id are cleared so the worker no
        // longer picks the task up for auto-retry.
        expect($failed->retry_after)->toBeNull();
        expect($failed->retry_of_task_id)->toBeNull();
        expect((int) $failed->retry_count)->toBe(0);
    });

    it('lets a group member cancel a retry chain on a shared agent (regression: principal-scoped filter, not user-principal)', function (): void {
        // Regression for the cancelRetryChain bulk-update filter: it
        // used to scope on `tasks.user_id = $userId`. After the
        // principal_id migration, scoping on the caller's user-principal
        // would silently no-op for group-shared chains (the chain's
        // principal_id is the group, not the user). Fix: scope on the
        // loaded task's principal_id so any group member with visibility
        // can cancel the chain.
        $authService = bootAuthLayer();
        $ownerId      = $authService->register('cancel-owner@example.com', 'Password1!', 'CancelOwner');
        $plainMemberId = $authService->register('cancel-member@example.com', 'Password1!', 'CancelMember');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);

        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'CancelGroup');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;

        $agent = Agent::create([
            'principal_id' => $groupPrincipalId, 'name' => 'CancelSharedAgent',
            'max_steps' => 5, 'is_active' => true,
        ]);
        // Failed task owned by the GROUP. Member B is the trigger, but
        // we're cancelling from the owner's perspective — group member
        // with visibility, not the original clicker.
        $failed = Task::create([
            'principal_id' => $groupPrincipalId, 'trigger_user_id' => $plainMemberId,
            'agent_id' => $agent->id, 'status' => 'FAILED',
            'user_prompt' => 'orig', 'max_steps' => 5, 'retry_count' => 1,
        ]);
        $failed->retry_of_task_id = $failed->id;
        $failed->save();
        Capsule::table('tasks')
            ->where('id', $failed->id)
            ->update(['retry_after' => date('Y-m-d H:i:s', time() + 600)]);

        $service = makeTaskService();
        expect($service->cancelRetryChain($failed->id, $ownerId))->toBeTrue();

        $failed->refresh();
        expect($failed->retry_after)->toBeNull()
            ->and($failed->retry_of_task_id)->toBeNull()
            ->and((int) $failed->retry_count)->toBe(0);
    });
});

/**
 * Group-shared visibility: the core regression from the user's bug
 * report. Without these tests the `principal_id`-based scoping on
 * `getTasksForUser` / `getTask` could silently regress back to the
 * runner-only `user_id` filter.
 */
describe('TaskService — group-shared run visibility (post-0071)', function (): void {

    it('returns another member\'s run on a group-shared agent (the bug fix)', function (): void {
        // Two members of the same group; member B's run on the group-owned
        // agent must show up in member A's list. Pre-0071 this would 404
        // because the legacy `where('user_id', $userId)` filter hid it.
        $authService = bootAuthLayer();
        $ownerId       = $authService->register('shared-owner@example.com', 'Password1!', 'SharedOwner');
        $plainMemberId = $authService->register('shared-member@example.com', 'Password1!', 'SharedMember');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);

        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'SharedVis');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);

        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;

        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'SharedVisAgent',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);

        // Member B creates a task on the shared agent.
        $task = Task::create([
            'agent_id'         => $agent->id,
            'principal_id'     => $groupPrincipalId,
            'trigger_user_id'  => $plainMemberId,
            'status'           => 'COMPLETED',
            'user_prompt'      => 'member B chat',
            'final_response'   => 'done',
            'max_steps'        => 5,
            'step_count'       => 1,
        ]);

        // Member A fetches — should see member B's run.
        $service = makeTaskService();
        $result  = $service->getTasksForUser($ownerId);

        expect($result)->toHaveCount(1)
            ->and($result[0]['id'])->toBe($task->id);
    });

    it('returns another member\'s run on a shared agent via getTask (deep-link works for group members)', function (): void {
        $authService = bootAuthLayer();
        $ownerId       = $authService->register('link-owner@example.com', 'Password1!', 'LinkOwner');
        $plainMemberId = $authService->register('link-member@example.com', 'Password1!', 'LinkMember');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);

        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'DeepLink');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);

        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;
        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'DeepLinkAgent',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);
        $task = Task::create([
            'agent_id'         => $agent->id,
            'principal_id'     => $groupPrincipalId,
            'trigger_user_id'  => $plainMemberId,
            'status'           => 'COMPLETED',
            'user_prompt'      => 'member B chat',
            'max_steps'        => 5,
        ]);

        // Member A deep-links to member B's run.
        $service = makeTaskService();
        $result  = $service->getTask($task->id, $ownerId);

        expect($result)->not->toBeNull()
            ->and($result['id'])->toBe($task->id);
    });

    it('does NOT return another user\'s runs on a private agent (no leak)', function (): void {
        $authService = bootAuthLayer();
        $ownerId     = $authService->register('priv-owner@example.com', 'Password1!', 'PrivOwner');
        $strangerId  = $authService->register('priv-stranger@example.com', 'Password1!', 'PrivStranger');

        $ownerPrincipalId = createUserPrincipalPublic($ownerId);

        $agent = Agent::create([
            'principal_id' => $ownerPrincipalId,
            'name'         => 'PrivAgent',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);
        Task::create([
            'agent_id'         => $agent->id,
            'principal_id'     => $ownerPrincipalId,
            'trigger_user_id'  => $ownerId,
            'status'           => 'COMPLETED',
            'user_prompt'      => 'private',
            'max_steps'        => 5,
        ]);

        // Stranger — different user, no group in common.
        $service = makeTaskService();
        $result  = $service->getTasksForUser($strangerId);

        expect($result)->toBeEmpty();
    });

    it('honours multiple group memberships (user in two groups sees both)', function (): void {
        $authService = bootAuthLayer();
        $aliceId     = $authService->register('multi-alice@example.com', 'Password1!', 'MultiAlice');
        $bobId       = $authService->register('multi-bob@example.com', 'Password1!', 'MultiBob');
        $carolId     = $authService->register('multi-carol@example.com', 'Password1!', 'MultiCarol');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($aliceId);
        $principalService->ensureUserPrincipal($bobId);
        $principalService->ensureUserPrincipal($carolId);

        $groupService = new Spora\Services\GroupService($principalService);

        // Alice + Bob in group G1
        $g1 = $groupService->createGroup($aliceId, 'MultiG1');
        $groupService->addMember((int) $g1->id, (int) $bobId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $aliceId);
        $g1PrincipalId = (int) $principalService->ensureGroupPrincipal((int) $g1->id)->id;

        // Alice + Carol in group G2
        $g2 = $groupService->createGroup($aliceId, 'MultiG2');
        $groupService->addMember((int) $g2->id, (int) $carolId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $aliceId);
        $g2PrincipalId = (int) $principalService->ensureGroupPrincipal((int) $g2->id)->id;

        $agent1 = Agent::create(['principal_id' => $g1PrincipalId, 'name' => 'G1Agent', 'max_steps' => 5, 'is_active' => true]);
        $agent2 = Agent::create(['principal_id' => $g2PrincipalId, 'name' => 'G2Agent', 'max_steps' => 5, 'is_active' => true]);

        Task::create([
            'agent_id' => $agent1->id, 'principal_id' => $g1PrincipalId,
            'trigger_user_id' => $bobId, 'status' => 'COMPLETED',
            'user_prompt' => 'G1', 'max_steps' => 5,
        ]);
        Task::create([
            'agent_id' => $agent2->id, 'principal_id' => $g2PrincipalId,
            'trigger_user_id' => $carolId, 'status' => 'COMPLETED',
            'user_prompt' => 'G2', 'max_steps' => 5,
        ]);

        // Alice sees both.
        $service = makeTaskService();
        $result  = $service->getTasksForUser($aliceId);

        expect($result)->toHaveCount(2);
    });

    it('does NOT leak group-shared runs to non-members', function (): void {
        $authService = bootAuthLayer();
        $ownerId     = $authService->register('gate-owner@example.com', 'Password1!', 'GateOwner');
        $memberId    = $authService->register('gate-member@example.com', 'Password1!', 'GateMember');
        $outsiderId  = $authService->register('gate-outsider@example.com', 'Password1!', 'GateOutsider');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($memberId);
        $principalService->ensureUserPrincipal($outsiderId);

        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'GateTest');
        $groupService->addMember((int) $group->id, (int) $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;

        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'GateAgent',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);
        Task::create([
            'agent_id' => $agent->id, 'principal_id' => $groupPrincipalId,
            'trigger_user_id' => $memberId, 'status' => 'COMPLETED',
            'user_prompt' => 'inside', 'max_steps' => 5,
        ]);

        // Outsider — not in the group, owns no group agents.
        $service = makeTaskService();
        $result  = $service->getTasksForUser($outsiderId);

        expect($result)->toBeEmpty();
    });
});

describe('TaskService — per-task actions widen to group-member (post-0071)', function (): void {

    it('any group member can approve a pending-approval task on a shared agent', function (): void {
        // Replaces the legacy runner-only guard. The owner-or-group-member
        // semantic is the v4 locked decision.
        $authService = bootAuthLayer();
        $ownerId      = $authService->register('approve-owner@example.com', 'Password1!', 'ApproveOwner');
        $plainMemberId = $authService->register('approve-member@example.com', 'Password1!', 'ApproveMember');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);

        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'ApproveTest');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;

        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'ApproveAgent',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);
        $task = Task::create([
            'agent_id' => $agent->id, 'principal_id' => $groupPrincipalId,
            'trigger_user_id' => $plainMemberId, 'status' => 'PENDING_APPROVAL',
            'user_prompt' => 'pending', 'max_steps' => 5,
        ]);

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publish')->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);
        $orchestrator->shouldReceive('resume')
            ->once()
            ->andReturnUsing(function () use ($task): void {
                Task::where('id', $task->id)->update(['status' => 'RUNNING']);
            });

        $service = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());
        $result  = $service->approveTask($task->id, $ownerId, [
            ['provider_call_id' => 'c1', 'decision' => 'approve'],
        ]);

        expect($result['status'])->toBe('RUNNING');
    });

    it('throws (404-shaped) when a non-group member tries to act on a shared-agent task', function (): void {
        $authService = bootAuthLayer();
        $ownerId      = $authService->register('guard-owner@example.com', 'Password1!', 'GuardOwner');
        $plainMemberId = $authService->register('guard-member@example.com', 'Password1!', 'GuardMember');
        $outsiderId    = $authService->register('guard-outsider@example.com', 'Password1!', 'GuardOutsider');

        $principalService = new Spora\Services\PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);
        $principalService->ensureUserPrincipal($outsiderId);

        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'GuardTest');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;

        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'GuardAgent',
            'max_steps'    => 5,
            'is_active'    => true,
        ]);
        $task = Task::create([
            'agent_id' => $agent->id, 'principal_id' => $groupPrincipalId,
            'trigger_user_id' => $plainMemberId, 'status' => 'PENDING_APPROVAL',
            'user_prompt' => 'pending', 'max_steps' => 5,
        ]);

        $service = makeTaskService();
        expect(fn() => $service->approveTask($task->id, $outsiderId, [
            ['provider_call_id' => 'c1', 'decision' => 'approve'],
        ]))->toThrow(InvalidArgumentException::class);
    });
});
