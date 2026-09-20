<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ValueObjects\AgentState;
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
use Spora\Tools\PendingQuestion;
use Spora\Tools\PendingQuestionBatch;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @return array{controller: TaskController, task: Task, userId: int, taskService: TaskService}
 */
function showTaskControllerForQuestionsHarness(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('show-q@example.com', 'Password1!', 'Show Questions');
    simulateLoggedInSession($userId, 'show-q@example.com');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Show Questions Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'          => 'AWAITING_INPUT',
        'user_prompt'     => 'pick a database',
        'step_count'      => 1,
        'max_steps'       => 10,
    ]);

    /** @var MercurePublisherInterface&\Mockery\MockInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();

    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn(null);
    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(toolInstances: []),
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

function showRequestForQuestions(TaskController $controller, int $taskId): JsonResponse
{
    $request = jsonRequest('GET', "/api/v1/tasks/{$taskId}");
    $request->attributes->set('taskId', $taskId);
    return $controller->show($request);
}

it('exposes pending_questions batches under data.task.pending_questions when pending_state has one', function (): void {
    $h = showTaskControllerForQuestionsHarness();

    // Drive the same write path the executor uses — AgentState with one
    // batch, serialised to JSON on tasks.pending_state.
    $state = new AgentState(
        taskId: $h['task']->id,
        agentId: $h['task']->agent_id,
        pendingToolCalls: [],
        messageSnapshot: [],
        stepCount: $h['task']->step_count,
        maxSteps: $h['task']->max_steps,
        pausedAt: '2026-09-20T10:30:00Z',
        pendingQuestions: [
            PendingQuestionBatch::build('pc_ask_1', [
                new PendingQuestion(
                    question: 'Pick a database',
                    header: 'DB',
                    options: [
                        ['label' => 'SQLite', 'description' => 'Zero-config', 'preview' => null],
                        ['label' => 'MySQL',  'description' => 'Shared host', 'preview' => null],
                    ],
                    multiple: false,
                    allowFreeText: true,
                ),
            ]),
        ],
    );

    Task::where('id', $h['task']->id)->update(['pending_state' => $state->toJson()]);

    $response = showRequestForQuestions($h['controller'], $h['task']->id);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    $questions = $body['data']['task']['pending_questions'];

    expect($questions)->toHaveCount(1)
        ->and($questions[0]['tool_call_id'])->toBe('pc_ask_1')
        ->and($questions[0]['questions'][0]['header'])->toBe('DB')
        ->and($questions[0]['questions'][0]['question'])->toBe('Pick a database')
        ->and($questions[0]['questions'][0]['options'][0]['label'])->toBe('SQLite')
        ->and($questions[0]['questions'][0]['multiple'])->toBeFalse()
        ->and($questions[0]['questions'][0]['allowFreeText'])->toBeTrue()
        ->and($questions[0]['created_at'])->toBeString();
});

it('returns pending_questions as null when pending_state is unset', function (): void {
    $h = showTaskControllerForQuestionsHarness();

    // pending_state stays null — typical for tasks that never paused
    // for input. The detail endpoint must return null so the frontend
    // can distinguish "no questions" from "empty list".
    $response = showRequestForQuestions($h['controller'], $h['task']->id);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['task']['pending_questions'])->toBeNull();
});

it('returns pending_questions as null when pending_state is malformed JSON', function (): void {
    $h = showTaskControllerForQuestionsHarness();

    // Mirrors the defensive fall-through in TickPhaseRunner::publishIntermediateState —
    // a corrupt column must not blow up the detail poll. Same answer
    // (null) as the "never paused" case so the frontend sees one shape.
    Task::where('id', $h['task']->id)->update(['pending_state' => '{not valid json']);

    $response = showRequestForQuestions($h['controller'], $h['task']->id);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['task']['pending_questions'])->toBeNull();
});

it('returns pending_questions as null when pending_state parses but has no batches', function (): void {
    $h = showTaskControllerForQuestionsHarness();

    // Edge case: an AgentState written but with zero batches (e.g. after
    // answerTask cleared them). The endpoint must still return null —
    // the UI uses null to mean "no picker", and an empty list would
    // force a different render branch.
    $state = new AgentState(
        taskId: $h['task']->id,
        agentId: $h['task']->agent_id,
        pendingToolCalls: [],
        messageSnapshot: [],
        stepCount: $h['task']->step_count,
        maxSteps: $h['task']->max_steps,
        pausedAt: '2026-09-20T10:30:00Z',
        pendingQuestions: [],
    );
    Task::where('id', $h['task']->id)->update(['pending_state' => $state->toJson()]);

    $response = showRequestForQuestions($h['controller'], $h['task']->id);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['task']['pending_questions'])->toBeNull();
});
