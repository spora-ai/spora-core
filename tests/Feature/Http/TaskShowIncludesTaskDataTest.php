<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Http\AnswerQuestionRequestValidator;
use Spora\Http\ContinueTaskDispatcher;
use Spora\Http\DecisionsRequestValidator;
use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskService;
use Spora\Todo\TodoStoreRegistry;
use Spora\Tools\TodoTool;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @return array{controller: TaskController, task: Task, userId: int, taskService: TaskService}
 */
function showTaskControllerHarness(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('show-data@example.com', 'Password1!', 'Show Data');
    simulateLoggedInSession($userId, 'show-data@example.com');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Show Data Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'write some todos',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    /** @var MercurePublisherInterface&\Mockery\MockInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();

    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn(null);
    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(toolInstances: [new TodoTool(new TodoStoreRegistry())]),
    );

    $taskService = new TaskService($orchestrator, $mercure, null, new PrincipalResolver());

    $controller = new TaskController(
        $authService,
        $taskService,
        new TaskMediaCapabilityService(),
        new ContinueTaskDispatcher($taskService, new TaskMediaCapabilityService()),
        new DecisionsRequestValidator($taskService),
        new AnswerQuestionRequestValidator(new PrincipalResolver()),
    );

    return [
        'controller'  => $controller,
        'task'        => $task,
        'userId'      => $userId,
        'taskService' => $taskService,
    ];
}

function showRequest(TaskController $controller, int $taskId): JsonResponse
{
    $request = jsonRequest('GET', "/api/v1/tasks/{$taskId}");
    $request->attributes->set('taskId', $taskId);
    return $controller->show($request);
}

it('exposes the latest TodoTool write under data.task.data.todos', function (): void {
    $h = showTaskControllerHarness();

    (new TodoTool(new TodoStoreRegistry()))->execute(
        arguments: [
            'op'    => 'write',
            'todos' => [
                ['content' => 'plan', 'status' => 'in_progress', 'id' => 'plan'],
                ['content' => 'run',  'status' => 'pending',     'id' => 'run'],
            ],
        ],
        agentId: $h['task']->agent_id,
        taskId: $h['task']->id,
    );

    $response = showRequest($h['controller'], $h['task']->id);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['task']['data']['todos']['items'])->toHaveCount(2)
        ->and($body['data']['task']['data']['todos']['items'][0]['id'])->toBe('plan')
        ->and($body['data']['task']['data']['todos']['items'][1]['id'])->toBe('run')
        ->and($body['data']['task']['data']['todos']['version'])->toBe(1);
});

it('exposes non-todo data keys (e.g. SubAgentTool spawned_sub_task_ids)', function (): void {
    $h = showTaskControllerHarness();

    // Seed `tasks.data` directly — mirrors what SubAgentTool / HandoverTool
    // write into the same column. The detail endpoint must surface those
    // keys verbatim so the chat UI can render the live view.
    Task::where('id', $h['task']->id)->update([
        'data' => json_encode([
            'spawned_sub_task_ids' => [101, 102],
            'custom_marker'        => 'keep-me',
        ], JSON_THROW_ON_ERROR),
    ]);

    $response = showRequest($h['controller'], $h['task']->id);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['task']['data']['spawned_sub_task_ids'])->toBe([101, 102])
        ->and($body['data']['task']['data']['custom_marker'])->toBe('keep-me');
});

it('returns data as null when tasks.data is unset', function (): void {
    $h = showTaskControllerHarness();

    $response = showRequest($h['controller'], $h['task']->id);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    // `tasks.data` is Eloquent-cast to array; an unset column surfaces as
    // null on the model, so the detail endpoint must too. The key is
    // always present so consumers can rely on `task.data.todos` access
    // without a `?? null` guard.
    expect($body['data']['task'])->toHaveKey('data')
        ->and($body['data']['task']['data'])->toBeNull();
});
