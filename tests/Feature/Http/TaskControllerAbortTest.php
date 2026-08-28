<?php

declare(strict_types=1);

use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

function seedAbortEndpointFixtures(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('abort-ep@example.com', TEST_PASSWORD, 'Abort EP');
    simulateLoggedInSession($userId, 'abort-ep@example.com');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'Abort EP Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    return [$userId, $agent];
}

it('POST /abort returns 200 with the aborted task on a RUNNING source', function (): void {
    [$userId, $agent] = seedAbortEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'user_id'     => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'RUNNING',
        'user_prompt' => 'live',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
    $orch->shouldReceive('abort')
        ->once()
        ->with($task->id)
        ->andReturnUsing(static function (int $id): Task {
            $row = Task::find($id);
            $row->status = 'ABORTED';
            $existing = is_array($row->data) ? $row->data : [];
            $existing['aborted_at'] = gmdate('Y-m-d H:i:s');
            $row->data = $existing;
            $row->save();
            return Task::find($id);
        });

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->shouldReceive('publish')->andReturn(true);
    $mercure->shouldReceive('publishToUser')->andReturn(true);

    $service = new TaskService($orch, $mercure);
    $controller = new TaskController(
        bootAuthLayer(),
        $service,
        new Spora\Services\MediaArchive\TaskMediaCapabilityService(),
        new Spora\Http\ContinueTaskDispatcher($service, new Spora\Services\MediaArchive\TaskMediaCapabilityService()),
        new Spora\Http\DecisionsRequestValidator($service),
        Spora\Agents\ValueObjects\WorkerRuntimeMode::Server,
        new Spora\Services\DbRateLimiter(),
        $mercure,
        Mockery::mock(Spora\Agents\OrchestratorInterface::class),
        600,
    );

    $req = new Request();
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->abort($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['task']['status'])->toBe('ABORTED')
        ->and($body['data']['task']['aborted_at'] ?? null)->not->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('POST /abort returns 409 when the task is in a non-abortable state (PENDING_APPROVAL)', function (): void {
    [$userId, $agent] = seedAbortEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'user_id'     => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'PENDING_APPROVAL',
        'user_prompt' => 'awaiting',
        'max_steps' => 5,
    ]);

    $service = Mockery::mock(TaskServiceInterface::class);
    $service->shouldReceive('abortTask')
        ->once()
        ->with($task->id, $userId)
        ->andThrow(new InvalidArgumentException('Cannot abort a task in status PENDING_APPROVAL.'));

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $controller = new TaskController(
        bootAuthLayer(),
        $service,
        new Spora\Services\MediaArchive\TaskMediaCapabilityService(),
        new Spora\Http\ContinueTaskDispatcher($service, new Spora\Services\MediaArchive\TaskMediaCapabilityService()),
        new Spora\Http\DecisionsRequestValidator($service),
        Spora\Agents\ValueObjects\WorkerRuntimeMode::Server,
        new Spora\Services\DbRateLimiter(),
        $mercure,
        Mockery::mock(Spora\Agents\OrchestratorInterface::class),
        600,
    );

    $req = new Request();
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->abort($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_CONFLICT);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_STATE');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('POST /abort returns 404 when the task is not found', function (): void {
    [$userId] = seedAbortEndpointFixtures();

    $service = Mockery::mock(TaskServiceInterface::class);
    $service->shouldReceive('abortTask')
        ->once()
        ->with(99999, $userId)
        ->andThrow(new InvalidArgumentException('Task not found.'));

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $controller = new TaskController(
        bootAuthLayer(),
        $service,
        new Spora\Services\MediaArchive\TaskMediaCapabilityService(),
        new Spora\Http\ContinueTaskDispatcher($service, new Spora\Services\MediaArchive\TaskMediaCapabilityService()),
        new Spora\Http\DecisionsRequestValidator($service),
        Spora\Agents\ValueObjects\WorkerRuntimeMode::Server,
        new Spora\Services\DbRateLimiter(),
        $mercure,
        Mockery::mock(Spora\Agents\OrchestratorInterface::class),
        600,
    );

    $req = new Request();
    $req->attributes->set('taskId', 99999);

    $resp = $controller->abort($req);
    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('POST /abort with no body works (no-body call)', function (): void {
    [$userId, $agent] = seedAbortEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'user_id'     => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'RUNNING',
        'user_prompt' => 'live',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
    $orch->shouldReceive('abort')->andReturnUsing(static function (int $id): Task {
        $row = Task::find($id);
        $row->status = 'ABORTED';
        $row->save();
        return Task::find($id);
    });

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->shouldReceive('publish')->andReturn(true);

    $service = new TaskService($orch, $mercure);
    $controller = new TaskController(
        bootAuthLayer(),
        $service,
        new Spora\Services\MediaArchive\TaskMediaCapabilityService(),
        new Spora\Http\ContinueTaskDispatcher($service, new Spora\Services\MediaArchive\TaskMediaCapabilityService()),
        new Spora\Http\DecisionsRequestValidator($service),
        Spora\Agents\ValueObjects\WorkerRuntimeMode::Server,
        new Spora\Services\DbRateLimiter(),
        $mercure,
        Mockery::mock(Spora\Agents\OrchestratorInterface::class),
        600,
    );

    $req = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], '');
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->abort($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
})->afterEach(fn() => Spora\Core\Database::resetBootState());
