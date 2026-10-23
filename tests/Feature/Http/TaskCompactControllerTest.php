<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Http\TaskCompactController;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * @return array{0: int, 1: Agent}
 */
function seedCompactEndpointFixtures(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('compact-ep@example.com', TEST_PASSWORD, 'Compact EP');
    simulateLoggedInSession($userId, 'compact-ep@example.com');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'Compact EP Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    return [$userId, $agent];
}

function compactEndpointController(TaskServiceInterface $service): TaskCompactController
{
    return new TaskCompactController(bootAuthLayer(), $service);
}

/**
 * @return array{0: TaskService, 1: Mockery\MockInterface}
 */
function compactEndpointService(OrchestratorInterface $orch): array
{
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->shouldReceive('publish')->andReturn(true);
    $mercure->shouldReceive('publishForPrincipal')->andReturn(true);
    $mercure->shouldReceive('publishToUser')->andReturn(true);

    return [new TaskService($orch, $mercure, null, new Spora\Services\PrincipalResolver()), $mercure];
}

it('POST /compact returns 200 and reports whether a summary was written', function (): void {
    [$userId, $agent] = seedCompactEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'COMPLETED',
        'user_prompt' => 'done',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('compactHistory')
        ->once()
        ->with($task->id)
        ->andReturn(true);

    [$service] = compactEndpointService($orch);
    $controller = compactEndpointController($service);

    $req = new Request();
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->compact($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['compacted'])->toBeTrue()
        ->and($body['data']['task']['id'])->toBe($task->id);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('POST /compact reports a history too short to summarize', function (): void {
    [$userId, $agent] = seedCompactEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'COMPLETED',
        'user_prompt' => 'done',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('compactHistory')
        ->once()
        ->with($task->id)
        ->andReturn(false);

    [$service] = compactEndpointService($orch);
    $controller = compactEndpointController($service);

    $req = new Request();
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->compact($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['compacted'])->toBeFalse();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('POST /compact returns 409 when the task is still running', function (): void {
    [$userId, $agent] = seedCompactEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'RUNNING',
        'user_prompt' => 'live',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldNotReceive('compactHistory');

    [$service] = compactEndpointService($orch);
    $controller = compactEndpointController($service);

    $req = new Request();
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->compact($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_CONFLICT);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_STATE');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('POST /compact returns 404 for a task the caller does not own', function (): void {
    [$userId, $agent] = seedCompactEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'COMPLETED',
        'user_prompt' => 'done',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldNotReceive('compactHistory');

    [$service] = compactEndpointService($orch);
    $controller = compactEndpointController($service);

    $req = new Request();
    $req->attributes->set('taskId', $task->id + 9999);

    $resp = $controller->compact($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('the compacted history keeps every turn and names the range the summary replaces', function (): void {
    [$userId, $agent] = seedCompactEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'COMPLETED',
        'user_prompt' => 'done',
        'max_steps' => 5,
    ]);

    for ($i = 0; $i < 8; $i++) {
        TaskHistory::create([
            'task_id'  => $task->id,
            'sequence' => $i,
            'role'     => $i % 2 === 0 ? 'user' : 'assistant',
            'content'  => "Message {$i}",
        ]);
    }

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('compactHistory')
        ->once()
        ->andReturnUsing(static function (int $taskId): bool {
            TaskHistory::create([
                'task_id'                   => $taskId,
                'sequence'                  => 8,
                'role'                      => 'summary',
                'content'                   => 'Compacted turns.',
                'summarized_sequence_range' => '0-2',
            ]);
            return true;
        });

    [$service] = compactEndpointService($orch);
    $controller = compactEndpointController($service);

    $req = new Request();
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->compact($req);
    $body = json_decode($resp->getContent(), true);

    $history = $body['data']['task']['history'];

    // Every turn is still there, and the summary names the rows it replaces
    // so the chat can tell them apart.
    expect(count($history))->toBe(9)
        ->and($history[0]['content'])->toBe('Message 0')
        ->and($history[8]['role'])->toBe('summary')
        ->and($history[8]['summarized_sequence_range'])->toBe('0-2');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('POST /compact reports a failed summary and leaves the history alone', function (): void {
    [$userId, $agent] = seedCompactEndpointFixtures();
    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'COMPLETED',
        'user_prompt' => 'done',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('compactHistory')
        ->once()
        ->andThrow(new Spora\Agents\Exceptions\CompactionFailedException(
            'The model returned no summary for the conversation, so the history was left unchanged.',
        ));

    [$service] = compactEndpointService($orch);
    $controller = compactEndpointController($service);

    $req = new Request();
    $req->attributes->set('taskId', $task->id);

    $resp = $controller->compact($req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('COMPACTION_FAILED')
        ->and($body['error']['message'])->toContain('left unchanged');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
