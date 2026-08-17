<?php

declare(strict_types=1);

use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\SubAgentService;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * SubAgentService — focused coverage for `publishParentState`'s data
 * allowlist. The MercurePublisher payload must only carry the documented
 * keys (spawned_sub_task_ids, sub_agent_expected_count, run_id); anything
 * else is dropped to keep the live-stream projection truthful.
 *
 * The full integration story lives in tests/Feature/SubAgentSpawnTest.php;
 * this file exercises the private allowlist surface directly because
 * publishing is a side-effect-only path that's inconvenient to cover
 * through the public spawn/cancel entry points.
 */
describe('SubAgentService::publishParentState data projection', function (): void {
    /** @var array<int, array{task_id: int, user_id: int, data: array<string, mixed>}> */
    $captured = [];
    /** @var SubAgentService|null */
    $service = null;

    beforeEach(function () use (&$captured, &$service): void {
        Spora\Core\Database::resetBootState();
        $authService = bootAuthLayer();
        $userId = $authService->register(
            'subagent-allowlist@example.com',
            TEST_PASSWORD,
            'Allowlist',
        );
        simulateLoggedInSession($userId, 'subagent-allowlist@example.com');

        Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name' => 'Allowlist Agent',
            'max_steps' => 5,
            'is_active' => true,
        ]);

        $captured = [];
        $publisher = Mockery::mock(MercurePublisherInterface::class);
        $publisher->shouldReceive('publish')->andReturnUsing(function (int $taskId, int $userId, array $payload) use (&$captured) {
            $captured[] = ['task_id' => $taskId, 'principal_id' => createUserPrincipalPublic($userId), 'data' => $payload['data'] ?? []];
            return true;
        });

        // The orchestrator factory is irrelevant for this test — we only
        // call publishParentState() through reflection. Use a regular
        // closure (not an arrow fn) so we can throw from inside it.
        $service = new SubAgentService(
            static function (): Spora\Agents\OrchestratorInterface {
                throw new RuntimeException('not used');
            },
            $publisher,
            WorkerMode::Sync,
        );
    });

    afterEach(function (): void {
        Mockery::close();
        Spora\Core\Database::resetBootState();
    });

    /**
     * Reflection helper to invoke the private publishParentState() without
     * pulling in the orchestrator. Reflection keeps the test laser-focused
     * on the allowlist behavior rather than the surrounding spawn flow.
     */
    $invokePublish = function (int $taskId) use (&$service): void {
        $ref = new ReflectionMethod(SubAgentService::class, 'publishParentState');
        $ref->setAccessible(true);
        $ref->invoke($service, $taskId, 1);
    };

    it('publishes ONLY the allowlist keys, even when the row has extras', function () use (&$invokePublish, &$captured): void {
        $authService = bootAuthLayer();
        $userId = $GLOBALS['__allowlist_user_id']
            ?? ($GLOBALS['__allowlist_user_id'] = $authService->currentUserId());
        $agent = Agent::query()->first() ?? Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'Allowlist Agent', 'max_steps' => 5, 'is_active' => true,
        ]);

        $parent = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'user_id'     => $userId,
            'agent_id' => $agent->id,
            'status' => 'AWAITING_SUB_AGENTS',
            'user_prompt' => 'parent',
            'max_steps' => 5,
            'data' => [
                'spawned_sub_task_ids' => [10, 11],
                'sub_agent_expected_count' => 2,
                'run_id' => 'run_42',
                // Below: not in allowlist, must be dropped
                'secret_token' => 'should-not-leak',
                'final_response' => 'oops',
            ],
        ]);

        $invokePublish((int) $parent->id);

        expect($captured)->toHaveCount(1);
        expect($captured[0]['data'])->toBe([
            'spawned_sub_task_ids' => [10, 11],
            'sub_agent_expected_count' => 2,
            'run_id' => 'run_42',
        ]);
        expect($captured[0]['data'])->not->toHaveKey('secret_token');
        expect($captured[0]['data'])->not->toHaveKey('final_response');
    });

    it('returns silently when the parent task is missing', function () use (&$invokePublish, &$captured): void {
        // No task with id 999 exists.
        $invokePublish(999);
        expect($captured)->toBe([]);
    });
});
