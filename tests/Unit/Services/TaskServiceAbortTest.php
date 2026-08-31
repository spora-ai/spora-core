<?php

declare(strict_types=1);

use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

function seedAbortTaskServiceFixtures(string $status, array $data = []): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('svc-abort-' . $status . '@example.com', TEST_PASSWORD, 'Svc Abort');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'Svc Abort Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => $status,
        'user_prompt' => 'orig',
        'max_steps' => 5,
        'data'      => $data,
    ]);

    return [$userId, $task];
}

/**
 * Lightweight in-memory MercurePublisherInterface that records every
 * publish() call. Mockery closures have shown unreliable capture
 * semantics across our parallel run setup; this is simpler and
 * matches what the service uses (string-keyed payload).
 */
final class CapturingMercure implements MercurePublisherInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $captured = [];

    public function publish(int $taskId, int $userId, array $taskData): bool
    {
        $this->captured[] = [
            'task_id' => $taskId,
            'principal_id' => createUserPrincipalPublic($userId),
            'status'  => $taskData['status'] ?? null,
        ];
        return true;
    }

    public function publishToUser(int $userId, array $data): bool
    {
        return true;
    }
}

function makeCapturingService(OrchestratorInterface $orchestrator): array
{
    $mercure = new CapturingMercure();
    return [new TaskService($orchestrator, $mercure, null, new Spora\Services\PrincipalResolver()), $mercure];
}

it('abortTask throws when the task is missing', function (): void {
    $authService = bootAuthLayer();
    $userId      = $authService->register('svc-missing@example.com', TEST_PASSWORD, 'Missing');

    [$service] = makeCapturingService(Mockery::mock(OrchestratorInterface::class));

    expect(fn() => $service->abortTask(999999, $userId))
        ->toThrow(InvalidArgumentException::class, 'Task not found');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('abortTask translates InvalidTaskTransitionException to InvalidArgumentException', function (): void {
    [$userId, $task] = seedAbortTaskServiceFixtures('PENDING_APPROVAL');

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('abort')
        ->once()
        ->andThrow(new InvalidTaskTransitionException('Cannot abort a task in status PENDING_APPROVAL.'));

    [$service] = makeCapturingService($orch);

    expect(fn() => $service->abortTask($task->id, $userId))
        ->toThrow(InvalidArgumentException::class, 'Cannot abort a task in status PENDING_APPROVAL.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('abortTask delegates to orchestrator and returns the post-committed resource', function (): void {
    [$userId, $task] = seedAbortTaskServiceFixtures('RUNNING');

    $abortedRow = Task::find($task->id);
    $abortedRow->status = 'ABORTED';
    $abortedRow->data = ['aborted_at' => gmdate('Y-m-d H:i:s')];
    $abortedRow->save();

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('abort')
        ->once()
        ->with($task->id)
        ->andReturn($abortedRow->fresh());

    [$service, $mercure] = makeCapturingService($orch);

    $result = $service->abortTask($task->id, $userId);

    expect($result['status'])->toBe('ABORTED')
        ->and($result['aborted_at'] ?? null)->not->toBeNull()
        ->and($mercure->captured)->toHaveCount(1)
        ->and($mercure->captured[0]['task_id'])->toBe($task->id)
        ->and($mercure->captured[0]['status'])->toBe('ABORTED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('abortTask enforces user ownership — other-user abort is 404', function (): void {
    [$userId, $task] = seedAbortTaskServiceFixtures('RUNNING');

    $owner = $authService2 = bootAuthLayer();
    $otherUserId = $owner->register('svc-other@example.com', TEST_PASSWORD, 'Other');

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldNotReceive('abort');

    [$service] = makeCapturingService($orch);

    expect(fn() => $service->abortTask($task->id, $otherUserId))
        ->toThrow(InvalidArgumentException::class, 'Task not found');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('abortSubAgentAndCascade aborts the child and walks the parent chain', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('svc-cascade@example.com', TEST_PASSWORD, 'Cascade');

    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'      => 'Cascade Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    // Build a 3-deep chain: grandparent → parent → child
    $grandparent = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'AWAITING_SUB_AGENTS',
        'user_prompt' => 'gp',
        'max_steps' => 5,
        'data'      => ['spawned_sub_task_ids' => [], 'sub_agent_expected_count' => 1],
    ]);
    $parent = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'parent_task_id' => $grandparent->id,
        'status'    => 'AWAITING_SUB_AGENTS',
        'user_prompt' => 'p',
        'max_steps' => 5,
        'data'      => ['spawned_sub_task_ids' => [], 'sub_agent_expected_count' => 1],
    ]);
    $child = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'parent_task_id' => $parent->id,
        'status'    => 'RUNNING',
        'user_prompt' => 'c',
        'max_steps' => 5,
    ]);

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('abort')->andReturnUsing(static function (int $id): Task {
        $task = Task::find($id);
        if ($task === null) {
            throw new RuntimeException('missing');
        }
        $task->status = 'ABORTED';
        $existingData = is_array($task->data) ? $task->data : [];
        $existingData['aborted_at'] = gmdate('Y-m-d H:i:s');
        $task->data = $existingData;
        $task->save();
        return Task::find($id);
    });

    [$service, $mercure] = makeCapturingService($orch);

    $result = $service->abortSubAgentAndCascade($child->id, $userId);

    expect($result['status'])->toBe('ABORTED');

    expect(Task::find($child->id)->status)->toBe('ABORTED')
        ->and(Task::find($parent->id)->status)->toBe('ABORTED')
        ->and(Task::find($grandparent->id)->status)->toBe('ABORTED');

    expect($mercure->captured)->toHaveCount(3)
        ->and(array_unique(array_column($mercure->captured, 'status')))->toBe(['ABORTED']);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('abortSubAgentAndCascade is a no-op for non-awaiting ancestors', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('svc-cascade-noop@example.com', TEST_PASSWORD, 'Cascade');

    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'      => 'Noop Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $parent = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'status'    => 'COMPLETED',
        'user_prompt' => 'already done',
        'max_steps' => 5,
    ]);
    $child = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'  => $agent->id,
        'parent_task_id' => $parent->id,
        'status'    => 'RUNNING',
        'user_prompt' => 'c',
        'max_steps' => 5,
    ]);

    $abortedIds = [];
    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('abort')->andReturnUsing(static function (int $id) use (&$abortedIds): Task {
        $abortedIds[] = $id;
        $task = Task::find($id);
        $task->status = 'ABORTED';
        $task->save();
        return Task::find($id);
    });

    [$service, $mercure] = makeCapturingService($orch);

    $service->abortSubAgentAndCascade($child->id, $userId);

    // Only the child gets aborted — parent is COMPLETED so cascade skips it
    expect($abortedIds)->toBe([$child->id])
        ->and(Task::find($parent->id)->status)->toBe('COMPLETED')
        ->and($mercure->captured)->toHaveCount(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continueTask persists aborted_at in buildBaseTaskResource', function (): void {
    [$userId, $task] = seedAbortTaskServiceFixtures('RUNNING');

    $orch = Mockery::mock(OrchestratorInterface::class);
    $orch->shouldReceive('continue')
        ->with($task->id, 'redirect', null, [])
        ->andReturnUsing(static function (int $id): Task {
            $t = Task::find($id);
            $t->status = 'ABORTED';
            $existing = is_array($t->data) ? $t->data : [];
            $existing['aborted_at'] = gmdate('Y-m-d H:i:s');
            $t->data = $existing;
            $t->save();
            return Task::find($id);
        });

    [$service] = makeCapturingService($orch);

    $result = $service->continueTask($task->id, $userId, 'redirect');

    expect($result['status'])->toBe('ABORTED')
        ->and($result['aborted_at'] ?? null)->not->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());
