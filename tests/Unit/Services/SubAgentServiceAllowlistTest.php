<?php

declare(strict_types=1);
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
        /** @var Mockery\MockInterface&MercurePublisherInterface $publisher */
        $publisher = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $publisher->shouldReceive('publishForPrincipal')->andReturnUsing(function (int $taskId, int $principalId, array $payload) use (&$captured) {
            $captured[] = ['task_id' => $taskId, 'principal_id' => $principalId, 'data' => $payload['data'] ?? []];
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
        $ref->invoke($service, $taskId);
    };

    it('publishes ONLY the allowlist keys, even when the row has extras', function () use (&$invokePublish, &$captured): void {
        $authService = bootAuthLayer();
        $userId = (int) $authService->currentUserId();
        $agent = Agent::query()->first() ?? Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'Allowlist Agent', 'max_steps' => 5, 'is_active' => true,
        ]);

        $parent = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id' => $agent->id,
            'status' => 'AWAITING_SUB_AGENTS',
            'user_prompt' => 'parent',
            'max_steps' => 5,
            'data' => [
                'spawned_sub_task_ids' => [10, 11],
                'sub_agent_expected_count' => 2,
                'run_id' => 'run_42',
                // Not in the allowlist — must be dropped
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

    it('publishes to the task\'s own principal, not to a user id', function () use (&$invokePublish, &$captured): void {
        // The topic is `principal/{principalId}/tasks`, and the two id
        // sequences are independent — a user id addresses whatever
        // principal happens to carry that number. The audience has to be
        // the task's principal.
        $authService = bootAuthLayer();
        $userId = (int) $authService->currentUserId();
        $agent = Agent::query()->first() ?? Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'Allowlist Agent', 'max_steps' => 5, 'is_active' => true,
        ]);

        $groupId = (int) Spora\Models\Group::create([
            'name'              => 'AllowlistGroup',
            'created_by_user_id' => $userId,
        ])->id;

        $groupPrincipalId = (int) Spora\Models\Principal::create([
            'type'     => Spora\Models\Principal::TYPE_GROUP,
            'group_id' => $groupId,
        ])->id;

        $parent = Task::create([
            'principal_id' => $groupPrincipalId,
            'trigger_user_id' => $userId,
            'agent_id' => $agent->id,
            'status' => 'AWAITING_SUB_AGENTS',
            'user_prompt' => 'parent',
            'max_steps' => 5,
        ]);

        $invokePublish((int) $parent->id);

        expect($captured)->toHaveCount(1);
        expect($captured[0]['principal_id'])->toBe($groupPrincipalId);

        // The audience is a principal, and this one is the group — not the
        // user, whose id lives in a different sequence and may well carry
        // the same number.
        $audience = Spora\Models\Principal::find($captured[0]['principal_id']);
        expect($audience)->not->toBeNull()
            ->and($audience->type)->toBe(Spora\Models\Principal::TYPE_GROUP);
    });

    it('returns silently when the parent task is missing', function () use (&$invokePublish, &$captured): void {
        // No task with id 999 exists.
        $invokePublish(999);
        expect($captured)->toBe([]);
    });
});

/**
 * The Mercure topic is `principal/{principalId}/tasks`. User ids and
 * principal ids are separate sequences, so publishing under the caller's
 * user id addresses a principal that has nothing to do with the task —
 * the event lands with the wrong audience, or with nobody.
 */
describe('SubAgentService publishes the parent state to the task\'s principal', function (): void {
    afterEach(function (): void {
        Mockery::close();
        Spora\Core\Database::resetBootState();
    });

    it('addresses the principal that owns the parent task', function (): void {
        $captured = [];
        /** @var Mockery\MockInterface&MercurePublisherInterface $publisher */
        $publisher = Mockery::mock(MercurePublisherInterface::class);
        $publisher->shouldReceive('publishForPrincipal')->andReturnUsing(function (int $taskId, int $principalId) use (&$captured): bool {
            $captured[] = ['task_id' => $taskId, 'principal_id' => $principalId];
            return true;
        });

        $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);

        $service = new SubAgentService(
            static fn(): Spora\Agents\OrchestratorInterface => $orchestrator,
            $publisher,
        );

        // A stub user with a fixed id, so the user sequence and the
        // principal sequence cannot collide.
        $ownerId = 900001;
        createUserPrincipalPublic($ownerId);

        $now = date('Y-m-d H:i:s');
        $groupId = (int) Spora\Models\Group::create([
            'name'              => 'PublishAudienceGroup',
            'created_by_user_id' => $ownerId,
        ])->id;
        Spora\Models\GroupMembership::create([
            'group_id' => $groupId,
            'user_id'  => $ownerId,
            'role'     => Spora\Models\GroupMembership::ROLE_OWNER,
        ]);
        $groupPrincipalId = (int) Spora\Models\Principal::create([
            'type'     => Spora\Models\Principal::TYPE_GROUP,
            'group_id' => $groupId,
        ])->id;

        $agentId = (int) Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'Audience Agent',
            'max_steps'    => 5,
            'is_active'    => true,
        ])->id;

        $parent = Task::create([
            'principal_id'    => $groupPrincipalId,
            'trigger_user_id' => $ownerId,
            'agent_id'        => $agentId,
            'status'          => 'RUNNING',
            'user_prompt'     => 'parent',
            'max_steps'       => 5,
        ]);
        $child = Task::create([
            'principal_id'    => $groupPrincipalId,
            'trigger_user_id' => $ownerId,
            'agent_id'        => $agentId,
            'status'          => 'COMPLETED',
            'user_prompt'     => 'child',
            'max_steps'       => 5,
            'parent_task_id'  => $parent->id,
        ]);

        $orchestrator->allows('continue')->andReturn($child);

        $service->continueChild(
            parentTaskId: (int) $parent->id,
            childTaskId: (int) $child->id,
            prompt: 'more',
            userId: $ownerId,
        );

        expect($captured)->toHaveCount(1);
        expect($captured[0]['task_id'])->toBe((int) $parent->id)
            ->and($captured[0]['principal_id'])->toBe($groupPrincipalId)
            ->and($captured[0]['principal_id'])->not->toBe($ownerId);
    });
});
