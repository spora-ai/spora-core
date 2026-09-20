<?php

declare(strict_types=1);

use Spora\Agents\TaskLifecyclePolicy;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Tools\Attributes\Tool;

function harnessTaskInRunningState(string $toolName, string $toolClass): Task
{
    $userId = bootAuthLayer()->register('ask-life@example.com', 'Password1!', 'Ask Lifecycle');
    simulateLoggedInSession($userId, 'ask-life@example.com');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Ask Lifecycle Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $attribute = (new ReflectionClass($toolClass))->getAttributes(Tool::class)[0]->newInstance();
    AgentTool::create([
        'agent_id'  => $agent->id,
        'tool_class' => $toolClass,
        'tool_name' => $attribute->name,
    ]);

    // ask_user_question is `enabledByDefault: false` (operators opt in).
    // Tests need the override row so the executor doesn't short-circuit
    // to OperationDisabled before reaching the parking branch.
    Spora\Models\AgentToolOperationOverride::create([
        'agent_id'    => $agent->id,
        'tool_class'  => $toolClass,
        'operation'   => $attribute->name === 'ask_user_question' ? 'ask' : 'write',
        'enabled'     => 1,
        'default_requires_approval' => 0,
    ]);

    return Task::create([
        'agent_id'       => $agent->id,
        'principal_id'   => $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'         => 'RUNNING',
        'user_prompt'    => 'Pose a question',
        'step_count'     => 0,
        'max_steps'      => 10,
    ]);
}

it('treats AWAITING_INPUT as quiescent and abort-eligible for `continue` (not `abort`)', function (): void {
    $policy = new TaskLifecyclePolicy();
    expect($policy->isQuiescent('AWAITING_INPUT'))->toBeTrue()
        ->and($policy->canAbortFrom('AWAITING_INPUT'))->toBeFalse()
        ->and($policy->canContinueFrom('AWAITING_INPUT'))->toBeFalse();
});

it('round-trips a PendingQuestionBatch through AgentState JSON', function (): void {
    $state = new AgentState(
        taskId: 1,
        agentId: 2,
        pendingToolCalls: [],
        messageSnapshot: [],
        stepCount: 3,
        maxSteps: 10,
        pausedAt: '2026-09-19T10:30:00Z',
        pendingQuestions: [
            Spora\Tools\PendingQuestionBatch::build('call_01', [
                new Spora\Tools\PendingQuestion(
                    question: 'Pick a DB',
                    header: 'DB',
                    options: [['label' => 'SQLite', 'description' => null, 'preview' => null]],
                    multiple: false,
                    allowFreeText: true,
                ),
            ]),
        ],
    );

    $json = $state->toJson();
    $hydrated = AgentState::fromJson($json);

    expect($hydrated->pendingQuestions)->toHaveCount(1)
        ->and($hydrated->pendingQuestions[0]->toolCallId)->toBe('call_01')
        ->and($hydrated->pendingQuestions[0]->questions)->toHaveCount(1)
        ->and($hydrated->pendingQuestions[0]->questions[0]->header)->toBe('DB')
        ->and($hydrated->pendingQuestions[0]->questions[0]->allowFreeText)->toBeTrue();
});

it('persists a PendingQuestionBatch on a Task via the executor path', function (): void {
    $task = harnessTaskInRunningState('ask_user_question', Spora\Tools\AskUserQuestionTool::class);

    // Drive the same executor path the orchestrator uses so we test the
    // real database write. We hand-build the ask_user_question call.
    $toolCall = new Spora\Drivers\ValueObjects\ToolCall(
        providerCallId: 'pc_ask_1',
        toolName: 'ask_user_question',
        arguments: [
            'questions' => [
                [
                    'question' => 'Pick a database',
                    'header'   => 'DB',
                    'options'  => [
                        ['label' => 'SQLite', 'description' => 'Zero-config'],
                        ['label' => 'MySQL', 'description' => 'Shared hosting'],
                    ],
                    'multiple' => false,
                    'allowFreeText' => true,
                ],
            ],
        ],
    );

    $driverFactory = Mockery::mock(Spora\Drivers\DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn(null);
    /** @var \Spora\Services\MercurePublisherInterface&\Mockery\MockInterface $mercure */
    $mercure = Mockery::mock(Spora\Services\MercurePublisherInterface::class)->shouldIgnoreMissing();
    $ask = new Spora\Tools\AskUserQuestionTool();
    $orch = new Spora\Agents\Orchestrator(
        $driverFactory,
        new Spora\Agents\OrchestratorConfig(toolInstances: [$ask], mercure: $mercure),
    );

    $agent = Agent::find($task->agent_id);
    $disposition = $orch->toolCallExecutor->executeOrQueue($toolCall, $agent, $task);

    expect($disposition)->toBe(Spora\Agents\ToolCallDisposition::AwaitingInput);

    $task->refresh();
    expect($task->status)->toBe('AWAITING_INPUT');

    $state = AgentState::fromJson($task->pending_state);
    expect($state->pendingQuestions)->toHaveCount(1)
        ->and($state->pendingQuestions[0]->toolCallId)->toBe('pc_ask_1')
        ->and($state->pendingQuestions[0]->questions[0]->header)->toBe('DB');

    // The tool row should be APPROVED (executed) with the placeholder content.
    $row = ToolCall::where('provider_call_id', 'pc_ask_1')->first();
    expect($row->status)->toBe('APPROVED')
        ->and($row->executed_at)->not->toBeNull();

    // The history should carry one row.
    $historyCount = TaskHistory::where('task_id', $task->id)
        ->where('tool_call_id', 'pc_ask_1')
        ->count();
    expect($historyCount)->toBe(1);
});

it('answerTask flips AWAITING_INPUT → QUEUED when no batches remain', function (): void {
    $task = harnessTaskInRunningState('ask_user_question', Spora\Tools\AskUserQuestionTool::class);
    $task->status = 'AWAITING_INPUT';
    $task->pending_state = json_encode([
        'task_id' => $task->id,
        'agent_id' => $task->agent_id,
        'pending_tool_calls' => [],
        'message_snapshot' => [],
        'step_count' => 0,
        'max_steps' => 10,
        'paused_at' => '2026-09-19T10:30:00Z',
        'pending_questions' => [
            [
                'tool_call_id' => 'pc_ask_2',
                'created_at' => '2026-09-19T10:30:00Z',
                'questions' => [[
                    'question' => 'Pick a DB',
                    'header' => 'DB',
                    'options' => [
                        ['label' => 'SQLite', 'description' => null, 'preview' => null],
                        ['label' => 'MySQL', 'description' => null, 'preview' => null],
                    ],
                    'multiple' => false,
                    'allowFreeText' => true,
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $task->save();

    $driverFactory = Mockery::mock(Spora\Drivers\DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn(null);
    $orch = new Spora\Agents\Orchestrator($driverFactory, new Spora\Agents\OrchestratorConfig(toolInstances: []));
    /** @var \Spora\Services\MercurePublisherInterface&\Mockery\MockInterface $mercure */
    $mercure = Mockery::mock(Spora\Services\MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

    $service = new Spora\Services\TaskService($orch, $mercure, null, new Spora\Services\PrincipalResolver());

    $service->answerTask($task->id, $task->trigger_user_id, 'pc_ask_2', '[ask_user_question selections: ["SQLite"]]');

    $task->refresh();
    expect($task->status)->toBe('QUEUED')
        ->and($task->pending_state)->toBeNull();

    $toolRow = TaskHistory::where('task_id', $task->id)
        ->where('tool_call_id', 'pc_ask_2')
        ->first();
    expect($toolRow)->not->toBeNull()
        ->and($toolRow->role)->toBe('tool')
        ->and($toolRow->content)->toContain('SQLite');
});

it('answerTask keeps AWAITING_INPUT when other batches remain pending', function (): void {
    $task = harnessTaskInRunningState('ask_user_question', Spora\Tools\AskUserQuestionTool::class);
    $task->status = 'AWAITING_INPUT';
    $task->pending_state = json_encode([
        'task_id' => $task->id,
        'agent_id' => $task->agent_id,
        'pending_tool_calls' => [],
        'message_snapshot' => [],
        'step_count' => 0,
        'max_steps' => 10,
        'paused_at' => '2026-09-19T10:30:00Z',
        'pending_questions' => [
            [
                'tool_call_id' => 'pc_ask_a',
                'created_at' => '2026-09-19T10:30:00Z',
                'questions' => [[
                    'question' => 'A', 'header' => 'A',
                    'options' => [['label' => 'a1', 'description' => null, 'preview' => null], ['label' => 'a2', 'description' => null, 'preview' => null]],
                    'multiple' => false, 'allowFreeText' => true,
                ]],
            ],
            [
                'tool_call_id' => 'pc_ask_b',
                'created_at' => '2026-09-19T10:31:00Z',
                'questions' => [[
                    'question' => 'B', 'header' => 'B',
                    'options' => [['label' => 'b1', 'description' => null, 'preview' => null], ['label' => 'b2', 'description' => null, 'preview' => null]],
                    'multiple' => false, 'allowFreeText' => true,
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $task->save();

    $driverFactory = Mockery::mock(Spora\Drivers\DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn(null);
    $orch = new Spora\Agents\Orchestrator($driverFactory, new Spora\Agents\OrchestratorConfig(toolInstances: []));
    /** @var \Spora\Services\MercurePublisherInterface&\Mockery\MockInterface $mercure */
    $mercure = Mockery::mock(Spora\Services\MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

    $service = new Spora\Services\TaskService($orch, $mercure, null, new Spora\Services\PrincipalResolver());

    $service->answerTask($task->id, $task->trigger_user_id, 'pc_ask_a', '[ask_user_question selections: ["a1"]]');

    $task->refresh();
    expect($task->status)->toBe('AWAITING_INPUT');

    $state = AgentState::fromJson($task->pending_state);
    expect($state->pendingQuestions)->toHaveCount(1)
        ->and($state->pendingQuestions[0]->toolCallId)->toBe('pc_ask_b');
});

it('rejects an answer that targets a tool_call_id not in pending_state', function (): void {
    $task = harnessTaskInRunningState('ask_user_question', Spora\Tools\AskUserQuestionTool::class);
    $task->status = 'AWAITING_INPUT';
    $task->pending_state = json_encode([
        'task_id' => $task->id,
        'agent_id' => $task->agent_id,
        'pending_tool_calls' => [],
        'message_snapshot' => [],
        'step_count' => 0,
        'max_steps' => 10,
        'paused_at' => '2026-09-19T10:30:00Z',
        'pending_questions' => [
            [
                'tool_call_id' => 'pc_real',
                'created_at' => '2026-09-19T10:30:00Z',
                'questions' => [[
                    'question' => 'Q', 'header' => 'H',
                    'options' => [['label' => 'a', 'description' => null, 'preview' => null], ['label' => 'b', 'description' => null, 'preview' => null]],
                    'multiple' => false, 'allowFreeText' => true,
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $task->save();

    $resolver = new Spora\Services\PrincipalResolver();
    $validator = new Spora\Http\AnswerQuestionRequestValidator($resolver);

    $result = $validator->parseAndValidate(
        ['tool_call_id' => 'pc_unknown', 'answers' => [['header' => 'H', 'selections' => ['a']]]],
        $task->id,
        $task->trigger_user_id,
    );
    expect($result)->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class)
        ->and($result->getStatusCode())->toBe(422);
});

it('rejects when answers count != questions count', function (): void {
    $task = harnessTaskInRunningState('ask_user_question', Spora\Tools\AskUserQuestionTool::class);
    $task->status = 'AWAITING_INPUT';
    $task->pending_state = json_encode([
        'task_id' => $task->id,
        'agent_id' => $task->agent_id,
        'pending_tool_calls' => [],
        'message_snapshot' => [],
        'step_count' => 0,
        'max_steps' => 10,
        'paused_at' => '2026-09-19T10:30:00Z',
        'pending_questions' => [
            [
                'tool_call_id' => 'pc_two_questions',
                'created_at' => '2026-09-19T10:30:00Z',
                'questions' => [
                    ['question' => 'Q1', 'header' => 'Q1', 'options' => [['label' => 'a', 'description' => null, 'preview' => null], ['label' => 'b', 'description' => null, 'preview' => null]], 'multiple' => false, 'allowFreeText' => true],
                    ['question' => 'Q2', 'header' => 'Q2', 'options' => [['label' => 'a', 'description' => null, 'preview' => null], ['label' => 'b', 'description' => null, 'preview' => null]], 'multiple' => false, 'allowFreeText' => true],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $task->save();

    $resolver = new Spora\Services\PrincipalResolver();
    $validator = new Spora\Http\AnswerQuestionRequestValidator($resolver);

    $result = $validator->parseAndValidate(
        ['tool_call_id' => 'pc_two_questions', 'answers' => [['header' => 'Q1', 'selections' => ['a']]]],
        $task->id,
        $task->trigger_user_id,
    );
    expect($result)->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
    $body = json_decode((string) $result->getContent(), true);
    expect($body['error']['message'])->toContain('Expected 2 answer(s), got 1');
});

it('rejects free-text answer when allowFreeText=false', function (): void {
    $task = harnessTaskInRunningState('ask_user_question', Spora\Tools\AskUserQuestionTool::class);
    $task->status = 'AWAITING_INPUT';
    $task->pending_state = json_encode([
        'task_id' => $task->id,
        'agent_id' => $task->agent_id,
        'pending_tool_calls' => [],
        'message_snapshot' => [],
        'step_count' => 0,
        'max_steps' => 10,
        'paused_at' => '2026-09-19T10:30:00Z',
        'pending_questions' => [
            [
                'tool_call_id' => 'pc_no_freetext',
                'created_at' => '2026-09-19T10:30:00Z',
                'questions' => [[
                    'question' => 'Q', 'header' => 'H',
                    'options' => [['label' => 'a', 'description' => null, 'preview' => null], ['label' => 'b', 'description' => null, 'preview' => null]],
                    'multiple' => false, 'allowFreeText' => false,
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $task->save();

    $resolver = new Spora\Services\PrincipalResolver();
    $validator = new Spora\Http\AnswerQuestionRequestValidator($resolver);

    $result = $validator->parseAndValidate(
        ['tool_call_id' => 'pc_no_freetext', 'answers' => [['header' => 'H', 'selections' => ['a'], 'free_text' => 'custom']]],
        $task->id,
        $task->trigger_user_id,
    );
    $body = json_decode((string) $result->getContent(), true);
    expect($result->getStatusCode())->toBe(422)
        ->and($body['error']['message'])->toContain('does not allow free-text answers');
});

it('rejects an unknown option label', function (): void {
    $task = harnessTaskInRunningState('ask_user_question', Spora\Tools\AskUserQuestionTool::class);
    $task->status = 'AWAITING_INPUT';
    $task->pending_state = json_encode([
        'task_id' => $task->id,
        'agent_id' => $task->agent_id,
        'pending_tool_calls' => [],
        'message_snapshot' => [],
        'step_count' => 0,
        'max_steps' => 10,
        'paused_at' => '2026-09-19T10:30:00Z',
        'pending_questions' => [
            [
                'tool_call_id' => 'pc_unknown_opt',
                'created_at' => '2026-09-19T10:30:00Z',
                'questions' => [[
                    'question' => 'Q', 'header' => 'H',
                    'options' => [['label' => 'a', 'description' => null, 'preview' => null], ['label' => 'b', 'description' => null, 'preview' => null]],
                    'multiple' => false, 'allowFreeText' => true,
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $task->save();

    $resolver = new Spora\Services\PrincipalResolver();
    $validator = new Spora\Http\AnswerQuestionRequestValidator($resolver);

    $result = $validator->parseAndValidate(
        ['tool_call_id' => 'pc_unknown_opt', 'answers' => [['header' => 'H', 'selections' => ['xyz']]]],
        $task->id,
        $task->trigger_user_id,
    );
    $body = json_decode((string) $result->getContent(), true);
    expect($result->getStatusCode())->toBe(422)
        ->and($body['error']['message'])->toContain("Unknown option 'xyz'");
});
