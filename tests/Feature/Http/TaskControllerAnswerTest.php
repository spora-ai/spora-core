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
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskService;
use Spora\Services\ToolCallSerializer;
use Spora\Tools\AskUserQuestionTool;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param Closure(MercurePublisherInterface&Mockery\MockInterface):void|null $mercureConfigure
 *   Optional callback to customise the Mercure mock after it's been
 *   created. When null (the default for tests that just need a
 *   well-behaved publisher), `publishForPrincipal` returns true. Pass a
 *   closure to override — e.g. to make `publishForPrincipal` throw —
 *   when testing the best-effort Mercure semantics.
 *
 * @return array{controller: TaskController, task: Task, principal_id: int, user_id: int, taskService: TaskService}
 */
function answerControllerHarness(?Closure $mercureConfigure = null): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('answer-ctrl@example.com', 'Password1!', 'Answer Ctrl');
    simulateLoggedInSession($userId, 'answer-ctrl@example.com');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Answer Ctrl Config',
        'driver_class' => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings' => json_encode(['api_key' => 'test'], JSON_THROW_ON_ERROR),
        'is_global' => true,
        'is_default' => true,
        'context_window' => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Answer Ctrl Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $askTool = new AskUserQuestionTool();
    AgentTool::create([
        'agent_id'  => $agent->id,
        'tool_class' => $askTool::class,
        'tool_name' => 'ask_user_question',
    ]);
    AgentToolOperationOverride::create([
        'agent_id'    => $agent->id,
        'tool_class'  => $askTool::class,
        'operation'   => 'ask',
        'enabled'     => 1,
        'default_requires_approval' => 0,
    ]);

    $task = Task::create([
        'agent_id'       => $agent->id,
        'principal_id'   => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'         => 'AWAITING_INPUT',
        'user_prompt'    => 'Pick a database',
        'step_count'     => 1,
        'max_steps'      => 10,
    ]);
    TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => 0,
        'role' => 'user',
        'content' => 'Pick a database',
    ]);

    $state = new AgentState(
        taskId: $task->id,
        agentId: $agent->id,
        pendingToolCalls: [],
        messageSnapshot: [],
        stepCount: 1,
        maxSteps: 10,
        pausedAt: date('Y-m-d\TH:i:s\Z'),
        pendingQuestions: [Spora\Tools\PendingQuestionBatch::build('pc_ask', [
            new Spora\Tools\PendingQuestion(
                question: 'Pick a DB',
                header: 'DB',
                options: [
                    ['label' => 'SQLite', 'description' => null, 'preview' => null],
                    ['label' => 'MySQL', 'description' => null, 'preview' => null],
                ],
                multiple: false,
                allowFreeText: true,
            ),
        ])],
    );
    $task->pending_state = $state->toJson();
    $task->save();

    /** @var MercurePublisherInterface&\Mockery\MockInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    if ($mercureConfigure !== null) {
        $mercureConfigure($mercure);
    } else {
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);
    }

    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn(null);
    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(toolInstances: [$askTool], mercure: $mercure),
    );
    $taskService = new TaskService(
        $orchestrator,
        $mercure,
        new ToolCallSerializer([$askTool]),
        new PrincipalResolver(),
    );

    $controller = new TaskController(
        $authService,
        $taskService,
        new TaskMediaCapabilityService(),
        new ContinueTaskDispatcher($taskService, new TaskMediaCapabilityService()),
        new DecisionsRequestValidator($taskService),
        new AnswerQuestionRequestValidator(new PrincipalResolver()),
    );

    return [
        'controller' => $controller,
        'task' => $task,
        'principal_id' => createUserPrincipalPublic($userId),
        'user_id' => $userId,
        'taskService' => $taskService,
    ];
}

function answerRequest(TaskController $controller, int $taskId, array $body): JsonResponse|Response
{
    $request = jsonRequest('POST', "/api/v1/tasks/{$taskId}/answer", $body);
    $request->attributes->set('taskId', $taskId);
    return $controller->answer($request);
}

it('returns 204 on a successful single-question batch answer', function (): void {
    $h = answerControllerHarness();
    $response = answerRequest($h['controller'], $h['task']->id, [
        'tool_call_id' => 'pc_ask',
        'answers' => [
            ['header' => 'DB', 'selections' => ['SQLite']],
        ],
    ]);
    expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT);

    $task = Task::find($h['task']->id);
    expect($task->status)->toBe('QUEUED')
        ->and($task->pending_state)->toBeNull();

    $toolRow = TaskHistory::where('task_id', $task->id)->where('tool_call_id', 'pc_ask')->first();
    expect($toolRow)->not->toBeNull()
        ->and($toolRow->role)->toBe('tool')
        ->and($toolRow->content)->toContain('SQLite');
});

// Symfony's JsonResponse with null data encodes to "{}" (2 bytes), so
// without explicit setContent('') the 204 would carry a body — a protocol
// violation that some HTTP intermediaries re-classify as 502. Guard the
// wire shape so a regression to `new JsonResponse(null, 204)` is caught.
it('returns 204 with an empty body on a successful answer', function (): void {
    $h = answerControllerHarness();
    $response = answerRequest($h['controller'], $h['task']->id, [
        'tool_call_id' => 'pc_ask',
        'answers' => [['header' => 'DB', 'selections' => ['SQLite']]],
    ]);
    expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT)
        ->and($response->getContent())->toBe('');
});

it('returns 422 when tool_call_id does not match', function (): void {
    $h = answerControllerHarness();
    $response = answerRequest($h['controller'], $h['task']->id, [
        'tool_call_id' => 'pc_unknown',
        'answers' => [['header' => 'DB', 'selections' => ['SQLite']]],
    ]);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 when answer count does not match question count', function (): void {
    $h = answerControllerHarness();
    $response = answerRequest($h['controller'], $h['task']->id, [
        'tool_call_id' => 'pc_ask',
        'answers' => [],
    ]);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 when an unknown selection label is supplied', function (): void {
    $h = answerControllerHarness();
    $response = answerRequest($h['controller'], $h['task']->id, [
        'tool_call_id' => 'pc_ask',
        'answers' => [['header' => 'DB', 'selections' => ['Postgres']]],
    ]);
    $body = json_decode((string) $response->getContent(), true);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and($body['error']['message'])->toContain("Unknown option 'Postgres'");
});

it('returns 400 on malformed JSON', function (): void {
    $h = answerControllerHarness();
    $request = Symfony\Component\HttpFoundation\Request::create(
        '/api/v1/tasks/' . $h['task']->id . '/answer',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{not json',
    );
    $request->attributes->set('taskId', $h['task']->id);
    $response = $h['controller']->answer($request);
    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
});

it('returns 422 when the task is not in AWAITING_INPUT', function (): void {
    $h = answerControllerHarness();
    Task::where('id', $h['task']->id)->update(['status' => 'RUNNING', 'pending_state' => null]);

    $response = answerRequest($h['controller'], $h['task']->id, [
        'tool_call_id' => 'pc_ask',
        'answers' => [['header' => 'DB', 'selections' => ['SQLite']]],
    ]);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 204 even when Mercure publishing throws', function (): void {
    // The Mercure publish is best-effort: the controller already
    // returned 204 by the time the publish call runs, so a wedged
    // hub must not regress an otherwise-successful answer submit into
    // a 502 on the proxy. Regression test for spora-core PR #259.
    $h = answerControllerHarness(static function ($mercure): void {
        $mercure->shouldReceive('publishForPrincipal')
            ->andThrow(new RuntimeException('mercure hub unreachable'));
    });

    $response = answerRequest($h['controller'], $h['task']->id, [
        'tool_call_id' => 'pc_ask',
        'answers' => [['header' => 'DB', 'selections' => ['SQLite']]],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT);

    $task = Task::find($h['task']->id);
    expect($task->status)->toBe('QUEUED')
        ->and($task->pending_state)->toBeNull();

    $toolRow = TaskHistory::where('task_id', $task->id)->where('tool_call_id', 'pc_ask')->first();
    expect($toolRow)->not->toBeNull()
        ->and($toolRow->role)->toBe('tool')
        ->and($toolRow->content)->toContain('SQLite');
});
