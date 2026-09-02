<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Http\ContinueTaskDispatcher;
use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;
use Spora\Services\ToolCallSerializer;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\StubOutputTool;
use Tests\Fixtures\StubOutputToolWithSchema;

/**
 * @param list<string> $providerCallIds
 * @return array{controller: TaskController, orchestrator: Orchestrator, task: Task, principal_id: int, observed_statuses: array<int, string>}
 */
function approvalFeatureHarness(
    array $providerCallIds,
    ?ToolInterface $tool = null,
): array {
    $tool ??= new StubOutputTool();
    $authService = bootAuthLayer();
    $userId = $authService->register('approval-feature@example.com', 'Password1!', 'Approval Feature');
    simulateLoggedInSession($userId, 'approval-feature@example.com');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Approval Feature Config',
        'driver_class' => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings' => json_encode(['api_key' => 'test'], JSON_THROW_ON_ERROR),
        'is_global' => true,
        'is_default' => true,
        'context_window' => 128000,
        'max_tokens_output' => 4096,
    ]);
    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Approval Feature Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $attribute = (new ReflectionClass($tool))->getAttributes(Tool::class)[0]->newInstance();
    AgentTool::create([
        'agent_id' => $agent->id,
        'tool_class' => $tool::class,
        'tool_name' => $attribute->name,
    ]);

    $task = Task::create([
        'agent_id' => $agent->id,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status' => 'PENDING_APPROVAL',
        'user_prompt' => 'Review these actions',
        'step_count' => 1,
        'max_steps' => 10,
    ]);
    TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => 0,
        'role' => 'user',
        'content' => 'Review these actions',
    ]);

    $pendingToolCalls = [];
    foreach ($providerCallIds as $providerCallId) {
        $arguments = $tool instanceof StubOutputToolWithSchema
            ? ['recipient' => 'valid@example.com']
            : ['value' => $providerCallId];
        $pendingToolCalls[] = new DriverToolCall($providerCallId, $attribute->name, $arguments);
        ToolCall::create([
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'provider_call_id' => $providerCallId,
            'tool_name' => $attribute->name,
            'tool_class' => $tool::class,
            'tool_type' => 'output',
            'operation' => 'default',
            'operation_description' => 'Run action',
            'status' => 'PENDING_APPROVAL',
            'proposed_arguments' => $arguments,
        ]);
    }

    $state = new AgentState(
        taskId: $task->id,
        agentId: $agent->id,
        pendingToolCalls: $pendingToolCalls,
        messageSnapshot: [],
        stepCount: 1,
        maxSteps: 10,
        pausedAt: date('Y-m-d\TH:i:s\Z'),
    );
    $task->pending_state = $state->toJson();
    $task->save();

    $observedStatuses = [];
    $taskId = $task->id;
    $llm = Mockery::mock(LLMDriverInterface::class);
    $llm->allows('complete')->andReturnUsing(function () use (&$observedStatuses, $taskId): LLMResponse {
        $observedStatuses[] = (string) Task::where('id', $taskId)->value('status');
        return new LLMResponse('Continued after decisions.', [], 5, 3, 'cmp_decisions');
    });
    $llm->allows('getProviderName')->andReturn('mock');
    $llm->allows('getModelName')->andReturn('mock-model');
    $llm->allows('supportsImageInput')->andReturn(false);

    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn($llm);
    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(toolInstances: [$tool]),
    );
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mercure */
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish');
    $taskService = new TaskService($orchestrator, $mercure, new ToolCallSerializer([$tool]), new Spora\Services\PrincipalResolver());
    $mediaCapability = new TaskMediaCapabilityService();
    $controller = new TaskController(
        $authService,
        $taskService,
        $mediaCapability,
        new ContinueTaskDispatcher($taskService, $mediaCapability),
        new Spora\Http\DecisionsRequestValidator($taskService),
    );

    return [
        'controller' => $controller,
        'orchestrator' => $orchestrator,
        'task' => $task,
        'principal_id' => createUserPrincipalPublic($userId),
        'observed_statuses' => &$observedStatuses,
    ];
}

/**
 * @param list<array<string, mixed>>|string $decisions
 */
function approvalFeatureRequest(TaskController $controller, int $taskId, array|string $decisions): JsonResponse
{
    $request = jsonRequest('POST', "/api/v1/tasks/{$taskId}/approve", ['decisions' => $decisions]);
    $request->attributes->set('taskId', $taskId);

    return $controller->approve($request);
}

it('applies a mixed approve and reject batch', function (): void {
    $harness = approvalFeatureHarness(['pc_approve', 'pc_reject']);

    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_approve', 'decision' => 'approve', 'arguments' => ['value' => 'approved']],
        ['provider_call_id' => 'pc_reject', 'decision' => 'reject', 'reason' => 'wrong recipient'],
    ]);
    // approve() only records the decisions — the approved call runs on the next tick.
    claimAndTick($harness['orchestrator'], $harness['task']->id);

    $approved = ToolCall::where('provider_call_id', 'pc_approve')->first();
    $rejected = ToolCall::where('provider_call_id', 'pc_reject')->first();
    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($harness['observed_statuses'])->toBe(['RUNNING'])
        ->and($approved->status)->toBe('APPROVED')
        ->and($approved->executed_at)->not->toBeNull()
        ->and($rejected->status)->toBe('REJECTED')
        ->and($rejected->reject_reason)->toBe('wrong recipient')
        ->and(TaskHistory::where('tool_call_id', 'pc_reject')->value('content'))->toBe('Action rejected by user: wrong recipient');
});

it('continues after every pending call is rejected', function (): void {
    $harness = approvalFeatureHarness(['pc_reject_1', 'pc_reject_2']);

    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_reject_1', 'decision' => 'reject', 'reason' => 'No'],
        ['provider_call_id' => 'pc_reject_2', 'decision' => 'reject', 'reason' => 'Also no'],
    ]);
    claimAndTick($harness['orchestrator'], $harness['task']->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($harness['observed_statuses'])->toBe(['RUNNING'])
        ->and(ToolCall::where('task_id', $harness['task']->id)->where('status', 'REJECTED')->count())->toBe(2)
        ->and(TaskHistory::where('task_id', $harness['task']->id)->where('role', 'tool')->count())->toBe(2);
});

it('executes approved calls and records the rejection in a three-call batch', function (): void {
    $harness = approvalFeatureHarness(['pc_a', 'pc_b', 'pc_c']);

    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_a', 'decision' => 'approve', 'arguments' => ['value' => 'a']],
        ['provider_call_id' => 'pc_b', 'decision' => 'reject', 'reason' => 'Not b'],
        ['provider_call_id' => 'pc_c', 'decision' => 'approve', 'arguments' => ['value' => 'c']],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and(ToolCall::where('task_id', $harness['task']->id)->where('status', 'APPROVED')->count())->toBe(2)
        ->and(ToolCall::where('provider_call_id', 'pc_b')->value('status'))->toBe('REJECTED')
        ->and(TaskHistory::where('tool_call_id', 'pc_b')->value('content'))->toContain('Not b');
});

it('defaults a missing reject reason', function (): void {
    $harness = approvalFeatureHarness(['pc_default']);

    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_default', 'decision' => 'reject'],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and(ToolCall::where('provider_call_id', 'pc_default')->value('reject_reason'))->toBe('User rejected')
        ->and(TaskHistory::where('tool_call_id', 'pc_default')->value('content'))->toBe('Action rejected by user: User rejected');
});

it('returns 422 when approve omits arguments', function (): void {
    $harness = approvalFeatureHarness(['pc_missing_args']);

    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_missing_args', 'decision' => 'approve'],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 when approved arguments fail the tool schema', function (): void {
    $harness = approvalFeatureHarness(['pc_schema'], new StubOutputToolWithSchema());

    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_schema', 'decision' => 'approve', 'arguments' => []],
    ]);

    $body = json_decode((string) $response->getContent(), true);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and($body['error']['message'])->toContain("Required argument 'recipient'")
        ->and(ToolCall::where('provider_call_id', 'pc_schema')->value('status'))->toBe('PENDING_APPROVAL');
});

it('returns 422 for an empty decisions list', function (): void {
    $harness = approvalFeatureHarness(['pc_empty']);
    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, []);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 for an unknown provider call id', function (): void {
    $harness = approvalFeatureHarness(['pc_known']);
    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_unknown', 'decision' => 'reject'],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 when decision is outside the enum', function (): void {
    $harness = approvalFeatureHarness(['pc_enum']);
    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_enum', 'decision' => 'skip'],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 when provider_call_id is missing', function (): void {
    $harness = approvalFeatureHarness(['pc_missing']);
    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['decision' => 'reject'],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 when a decision entry is not an object', function (): void {
    $harness = approvalFeatureHarness(['pc_item']);
    $invalidDecisions = 'not-an-array';
    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, $invalidDecisions);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('returns 422 when a rejection reason is not a string', function (): void {
    $harness = approvalFeatureHarness(['pc_reason']);
    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_reason', 'decision' => 'reject', 'reason' => []],
    ]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('records worker approvals for pickup while keeping undecided calls pending', function (): void {
    $harness = approvalFeatureHarness(['pc_worker_approve', 'pc_worker_reject', 'pc_worker_pending']);

    $response = approvalFeatureRequest($harness['controller'], $harness['task']->id, [
        ['provider_call_id' => 'pc_worker_approve', 'decision' => 'approve', 'arguments' => ['value' => 'approved']],
        ['provider_call_id' => 'pc_worker_reject', 'decision' => 'reject', 'reason' => 'No worker action'],
    ]);

    $harness['task']->refresh();
    $approved = ToolCall::where('provider_call_id', 'pc_worker_approve')->first();
    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($harness['task']->status)->toBe('PENDING_APPROVAL')
        ->and($approved->status)->toBe('APPROVED')
        ->and($approved->executed_at)->toBeNull()
        ->and(ToolCall::where('provider_call_id', 'pc_worker_reject')->value('status'))->toBe('REJECTED')
        ->and(TaskHistory::where('tool_call_id', 'pc_worker_reject')->exists())->toBeTrue();
});
