<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Services\MercurePublisherInterface;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeTaskController(?OrchestratorInterface $orch = null): array
{
    $authService = bootAuthLayer();
    $orch      ??= Mockery::mock(OrchestratorInterface::class);
    $mercure     = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);
    $controller  = new TaskController($authService, $orch, $mercure);

    return [$controller, $authService, $orch];
}

function seedUserAndAgent(mixed $authService): array
{
    $userId = $authService->register('task@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'task@example.com');

    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    return [$userId, $agent];
}

// ---------------------------------------------------------------------------
// Unauthenticated requests
// ---------------------------------------------------------------------------

it('unauthenticated index throws UnauthenticatedException', function (): void {
    [$controller] = makeTaskController();
    clearSession();

    expect(fn() => $controller->index(jsonRequest('GET', '/api/v1/tasks')))
        ->toThrow(UnauthenticatedException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('unauthenticated store throws UnauthenticatedException', function (): void {
    [$controller] = makeTaskController();
    clearSession();

    expect(fn() => $controller->store(jsonRequest('POST', '/api/v1/tasks', ['prompt' => 'hi'])))
        ->toThrow(UnauthenticatedException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// store()
// ---------------------------------------------------------------------------

it('store returns 422 when prompt is missing', function (): void {
    [$controller, $authService] = makeTaskController();
    seedUserAndAgent($authService);

    $resp = $controller->store(jsonRequest('POST', '/api/v1/tasks', []));
    expect($resp->getStatusCode())->toBe(422);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('store creates task via orchestrator and returns 201', function (): void {
    $mockTask = new Task([
        'id'          => 1,
        'agent_id'    => 1,
        'user_id'     => 1,
        'status'      => 'RUNNING',
        'user_prompt' => 'Hello',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    $mockTask->id = 1;

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->expects('start')->once()->andReturn($mockTask);

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent] = seedUserAndAgent($authService);

    $resp = $controller->store(jsonRequest('POST', '/api/v1/tasks', ['agent_id' => $agent->id, 'prompt' => 'Hello']));
    expect($resp->getStatusCode())->toBe(201);

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['task']['status'])->toBe('RUNNING');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('store returns 404 when user has no agent', function (): void {
    [$controller, $authService] = makeTaskController();
    $userId = $authService->register('noagent@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'noagent@example.com');

    $resp = $controller->store(jsonRequest('POST', '/api/v1/tasks', ['agent_id' => 99999, 'prompt' => 'hello']));
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// index()
// ---------------------------------------------------------------------------

it('index returns list of tasks for the authenticated user', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'Test',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);

    $resp = $controller->index(jsonRequest('GET', '/api/v1/tasks'));
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['tasks'])->toHaveCount(1)
        ->and($body['data']['tasks'][0]['status'])->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// show()
// ---------------------------------------------------------------------------

it('show returns 404 for unknown task', function (): void {
    [$controller, $authService] = makeTaskController();
    seedUserAndAgent($authService);

    $req = jsonRequest('GET', '/api/v1/tasks/999');
    $req->attributes->set('taskId', 999);

    $resp = $controller->show($req);
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('show returns task detail with history and tool_calls', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'Detail test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('GET', "/api/v1/tasks/{$task->id}");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->show($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['task'])->toHaveKey('history')
        ->and($body['data']['task'])->toHaveKey('tool_calls');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('show respects since_sequence to filter task history', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'Sequence test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'First']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'Second']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'Third']);

    $req = jsonRequest('GET', "/api/v1/tasks/{$task->id}?since_sequence=1");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->show($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode($resp->getContent(), true);
    $history = $body['data']['task']['history'];
    expect($history)->toHaveCount(1)
        ->and($history[0]['sequence'])->toBe(2)
        ->and($history[0]['content'])->toBe('Third');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// approve()
// ---------------------------------------------------------------------------

it('approve returns 409 when task is not PENDING_APPROVAL', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'x',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/approve");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->approve($req);
    expect($resp->getStatusCode())->toBe(409);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('approve calls orchestrator resume and returns updated task', function (): void {
    [$userId, $agent] = [null, null];
    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->expects('resume')->once();

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'approve test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/approve", [
        'provider_call_id' => 'call_abc',
        'arguments'        => ['key' => 'value'],
    ]);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->approve($req);
    expect($resp->getStatusCode())->toBe(200);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// reject()
// ---------------------------------------------------------------------------

it('reject returns 409 when task is not PENDING_APPROVAL', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'x',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/reject", ['reason' => 'No']);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->reject($req);
    expect($resp->getStatusCode())->toBe(409);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('reject calls orchestrator reject and returns 200', function (): void {
    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->expects('reject')->once();

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'reject test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/reject", ['reason' => 'Too risky']);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->reject($req);
    expect($resp->getStatusCode())->toBe(200);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Fix: legacy approve format must have a non-empty provider_call_id
// ---------------------------------------------------------------------------

it('approve returns 422 when legacy format omits provider_call_id', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    // Legacy format with no provider_call_id at all
    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/approve", ['arguments' => ['x' => 1]]);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->approve($req);
    expect($resp->getStatusCode())->toBe(422);

    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('approve returns 422 when legacy format has empty string provider_call_id', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/approve", [
        'provider_call_id' => '   ', // whitespace-only
        'arguments'        => [],
    ]);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->approve($req);
    expect($resp->getStatusCode())->toBe(422);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('approve legacy format with valid provider_call_id still calls orchestrator resume', function (): void {
    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->expects('resume')->once()->withArgs(function (int $taskId, array $batch): bool {
        return $batch[0]['provider_call_id'] === 'call_valid';
    });

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/approve", [
        'provider_call_id' => 'call_valid',
        'arguments'        => ['x' => 1],
    ]);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->approve($req);
    expect($resp->getStatusCode())->toBe(200);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// destroy()
// ---------------------------------------------------------------------------

it('destroy throws UnauthenticatedException when not logged in', function (): void {
    [$controller] = makeTaskController();
    clearSession();

    $req = jsonRequest('DELETE', '/api/v1/tasks/1');
    $req->attributes->set('taskId', 1);

    expect(fn() => $controller->destroy($req))
        ->toThrow(UnauthenticatedException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('destroy returns 404 for unknown task', function (): void {
    [$controller, $authService] = makeTaskController();
    seedUserAndAgent($authService);

    $req = jsonRequest('DELETE', '/api/v1/tasks/99999');
    $req->attributes->set('taskId', 99999);

    $resp = $controller->destroy($req);
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('destroy returns 404 for task belonging to another user', function (): void {
    [$controller, $authService] = makeTaskController();
    $userId = $authService->register('other@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'other@example.com');

    $otherAgent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Other Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $otherTask = Task::create([
        'agent_id'    => $otherAgent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'Other user task',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    // Authenticated as a different user — the seeded user has no access
    [$controller, $authService] = makeTaskController();
    seedUserAndAgent($authService); // logs in as 'task@example.com'

    $req = jsonRequest('DELETE', "/api/v1/tasks/{$otherTask->id}");
    $req->attributes->set('taskId', $otherTask->id);

    $resp = $controller->destroy($req);
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('destroy deletes task and returns 204', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent] = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'Delete me',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('DELETE', "/api/v1/tasks/{$task->id}");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->destroy($req);
    expect($resp->getStatusCode())->toBe(204);
    expect(Task::find($task->id))->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('destroy cascade-deletes task_history rows', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent] = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'History test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'First']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'Second']);

    $req = jsonRequest('DELETE', "/api/v1/tasks/{$task->id}");
    $req->attributes->set('taskId', $task->id);

    $controller->destroy($req);

    expect(TaskHistory::where('task_id', $task->id)->count())->toBe(0);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('destroy cascade-deletes tool_calls rows', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent] = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'ToolCalls test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    ToolCall::create([
        'task_id'          => $task->id,
        'agent_id'         => $agent->id,
        'provider_call_id' => 'call_delete_test',
        'tool_name'        => 'stub_output',
        'tool_class'       => 'StubOutputTool',
        'tool_type'        => 'function',
        'status'           => 'EXECUTED',
        'proposed_arguments' => [],
        'approved_arguments' => ['key' => 'val'],
    ]);

    $req = jsonRequest('DELETE', "/api/v1/tasks/{$task->id}");
    $req->attributes->set('taskId', $task->id);

    $controller->destroy($req);

    expect(ToolCall::where('task_id', $task->id)->count())->toBe(0);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// retry()
// ---------------------------------------------------------------------------

it('retry returns 404 for unknown task', function (): void {
    [$controller, $authService] = makeTaskController();
    seedUserAndAgent($authService);

    $req = jsonRequest('POST', '/api/v1/tasks/99999/retry');
    $req->attributes->set('taskId', 99999);

    $resp = $controller->retry($req);
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('retry returns 409 when task is not FAILED', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'x',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/retry");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->retry($req);
    expect($resp->getStatusCode())->toBe(409);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('retry calls orchestrator->start() with same agent_id and user_prompt', function (): void {
    $agentIdCapture = null;
    $promptCapture = '';

    $mockNewTask = new Task([
        'id'          => 2,
        'agent_id'    => 1,
        'user_id'     => 1,
        'status'      => 'RUNNING',
        'user_prompt' => 'Retry me',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    $mockNewTask->id = 2;

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->expects('start')->once()->withArgs(function (int $agentId, string $prompt, int $maxSteps) use (&$agentIdCapture, &$promptCapture): bool {
        $agentIdCapture = $agentId;
        $promptCapture = $prompt;
        return true;
    })->andReturn($mockNewTask);

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'FAILED',
        'user_prompt' => 'Retry me',
        'step_count'  => 3,
        'max_steps'   => 10,
        'error_code'  => 'SERVER_ERROR',
        'error_message' => 'The AI service encountered an error.',
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/retry");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->retry($req);
    expect($resp->getStatusCode())->toBe(201);

    expect($agentIdCapture)->toBe($agent->id);
    expect($promptCapture)->toBe('Retry me');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('retry returns 201 with the new task resource', function (): void {
    $mockTask = new Task([
        'id'          => 2,
        'agent_id'    => 1,
        'user_id'     => 1,
        'status'      => 'RUNNING',
        'user_prompt' => 'Retry me',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    $mockTask->id = 2;

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->allows('start')->andReturn($mockTask);

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'FAILED',
        'user_prompt' => 'Retry me',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/retry");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->retry($req);
    expect($resp->getStatusCode())->toBe(201);

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['task']['id'])->toBe(2);
    expect($body['data']['task']['status'])->toBe('RUNNING');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// show() includes error_code and error_message in response
// ---------------------------------------------------------------------------

it('show returns error_code and error_message when set on task', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'FAILED',
        'user_prompt' => 'Error test',
        'step_count'  => 1,
        'max_steps'   => 10,
        'error_code'  => 'SERVER_OVERLOADED',
        'error_message' => 'The AI service is under high load.',
    ]);

    $req = jsonRequest('GET', "/api/v1/tasks/{$task->id}");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->show($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['task']['error_code'])->toBe('SERVER_OVERLOADED');
    expect($body['data']['task']['error_message'])->toBe('The AI service is under high load.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// continue()
// ---------------------------------------------------------------------------

it('continue returns 200 and resets task for completed task', function (): void {
    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->expects('continue')->once()->with(
        Mockery::any(),
        'continue prompt',
        null,
    )->andReturnUsing(function ($taskId) {
        return Task::find($taskId);
    });

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'original',
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/continue", ['prompt' => 'continue prompt']);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->continue($req);
    expect($resp->getStatusCode())->toBe(200);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continue returns 200 and resets task for failed task', function (): void {
    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->expects('continue')->once()->with(
        Mockery::any(),
        'retry prompt',
        20,
    )->andReturnUsing(function ($taskId) {
        return Task::find($taskId);
    });

    [$controller, $authService] = makeTaskController($orch);
    [$userId, $agent]          = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'FAILED',
        'user_prompt' => 'original',
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/continue", [
        'prompt'           => 'retry prompt',
        'additional_steps' => 20,
    ]);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->continue($req);
    expect($resp->getStatusCode())->toBe(200);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continue returns 422 when prompt is missing', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'original',
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/continue", []);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->continue($req);
    expect($resp->getStatusCode())->toBe(422);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continue returns 422 when additional_steps is out of range', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'original',
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    foreach ([0, -1, 101] as $badValue) {
        $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/continue", [
            'prompt'           => 'test',
            'additional_steps' => $badValue,
        ]);
        $req->attributes->set('taskId', $task->id);

        $resp = $controller->continue($req);
        expect($resp->getStatusCode())->toBe(422);
    }
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continue returns 409 when task is not completed or failed', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'original',
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $req = jsonRequest('POST', "/api/v1/tasks/{$task->id}/continue", ['prompt' => 'test']);
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->continue($req);
    expect($resp->getStatusCode())->toBe(409);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continue returns 404 for unknown task', function (): void {
    [$controller, $authService] = makeTaskController();
    seedUserAndAgent($authService);

    $req = jsonRequest('POST', '/api/v1/tasks/99999/continue', ['prompt' => 'test']);
    $req->attributes->set('taskId', 99999);

    $resp = $controller->continue($req);
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// cancelRetryChain()
// ---------------------------------------------------------------------------

it('cancelRetryChain returns 404 for unknown task', function (): void {
    [$controller, $authService] = makeTaskController();
    seedUserAndAgent($authService);

    $req = jsonRequest('DELETE', '/api/v1/tasks/99999/cancel-retry-chain');
    $req->attributes->set('taskId', 99999);

    $resp = $controller->cancelRetryChain($req);
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('cancelRetryChain returns 409 when task is not in a retry chain', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'test',
        'step_count'  => 5,
        'max_steps'   => 10,
        'retry_of_task_id' => null,
    ]);

    $req = jsonRequest('DELETE', "/api/v1/tasks/{$task->id}/cancel-retry-chain");
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->cancelRetryChain($req);
    expect($resp->getStatusCode())->toBe(409);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('cancelRetryChain returns 204 and cancels chain for valid retry task', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]           = seedUserAndAgent($authService);

    $root = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'root',
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $retry1 = Task::create([
        'agent_id'          => $agent->id,
        'user_id'           => $userId,
        'status'            => 'FAILED',
        'user_prompt'      => 'retry 1',
        'step_count'        => 1,
        'max_steps'         => 10,
        'retry_of_task_id'  => $root->id,
        'retry_count'       => 1,
    ]);

    $retry2 = Task::create([
        'agent_id'          => $agent->id,
        'user_id'           => $userId,
        'status'            => 'FAILED',
        'user_prompt'      => 'retry 2',
        'step_count'        => 1,
        'max_steps'         => 10,
        'retry_of_task_id'  => $root->id,
        'retry_count'       => 2,
    ]);

    $req = jsonRequest('DELETE', "/api/v1/tasks/{$retry1->id}/cancel-retry-chain");
    $req->attributes->set('taskId', $retry1->id);

    $resp = $controller->cancelRetryChain($req);
    expect($resp->getStatusCode())->toBe(204);

    $retry1->refresh();
    $retry2->refresh();
    expect($retry1->status)->toBe('CANCELLED');
    expect($retry2->status)->toBe('CANCELLED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('cancelRetryChain returns 404 when trying to cancel another users retry chain', function (): void {
    [$controller, $authService] = makeTaskController();
    [$userId, $agent]            = seedUserAndAgent($authService);

    $otherUserId = $authService->register('other@example.com', 'Password1!');
    $otherUser = Spora\Models\User::where('email', 'other@example.com')->first();

    $root = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $otherUser->id,
        'status'      => 'COMPLETED',
        'user_prompt' => 'root',
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $retry = Task::create([
        'agent_id'          => $agent->id,
        'user_id'           => $otherUser->id,
        'status'            => 'FAILED',
        'user_prompt'      => 'retry',
        'step_count'        => 1,
        'max_steps'         => 10,
        'retry_of_task_id'  => $root->id,
        'retry_count'       => 1,
    ]);

    // Authenticated as userId, but trying to cancel a task owned by otherUser
    $req = jsonRequest('DELETE', "/api/v1/tasks/{$retry->id}/cancel-retry-chain");
    $req->attributes->set('taskId', $retry->id);

    $resp = $controller->cancelRetryChain($req);
    expect($resp->getStatusCode())->toBe(404);
})->afterEach(fn() => Spora\Core\Database::resetBootState());
