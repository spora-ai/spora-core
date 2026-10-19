<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\PrincipalResolver;
use Tests\Concerns\CreatesPrincipal;

/**
 * The gate on minting an API key, and on editing an agent.
 *
 * It mirrors `GroupAuthorizationTrait::callerCanManageGroup()` deliberately —
 * the group rule has one definition, not two that can drift.
 */
describe('PrincipalResolver::canManagePrincipal', function (): void {
    uses(CreatesPrincipal::class);

    it('lets a user manage their own principal', function (): void {
        $auth = bootAuthLayer();
        $userId = $auth->register('owner@example.com', 'Password1!', 'Owner');
        $principalId = createUserPrincipalPublic($userId);

        expect((new PrincipalResolver())->canManagePrincipal($principalId, $userId))->toBeTrue();
    });

    it('refuses another user\'s principal', function (): void {
        $auth = bootAuthLayer();
        $mine = createUserPrincipalPublic($auth->register('mine@example.com', 'Password1!', 'Mine'));
        $theirs = createUserPrincipalPublic($auth->register('theirs@example.com', 'Password1!', 'Theirs'));

        expect((new PrincipalResolver())->canManagePrincipal($theirs, (int) $mine))->toBeFalse();
    });

    it('refuses a principal that does not exist', function (): void {
        $auth = bootAuthLayer();
        $userId = $auth->register('ghost@example.com', 'Password1!', 'Ghost');

        expect((new PrincipalResolver())->canManagePrincipal(999999, $userId))->toBeFalse();
    });

    it('lets a group owner and admin manage, and refuses a plain member', function (): void {
        $auth = bootAuthLayer();
        $ownerId = $auth->register('gowner@example.com', 'Password1!', 'G Owner');
        $adminId = $auth->register('gadmin@example.com', 'Password1!', 'G Admin');
        $memberId = $auth->register('gmember@example.com', 'Password1!', 'G Member');

        $principalId = $this->makeGroupPrincipal($ownerId, 'Roles Group');
        $groupId = (int) Principal::findOrFail($principalId)->group_id;

        // `makeGroupPrincipal` already made the owner a plain member.
        GroupMembership::where('group_id', $groupId)->where('user_id', $ownerId)
            ->update(['role' => GroupMembership::ROLE_OWNER]);

        foreach ([$adminId => GroupMembership::ROLE_ADMIN, $memberId => GroupMembership::ROLE_MEMBER] as $userId => $role) {
            GroupMembership::create([
                'group_id' => $groupId,
                'user_id' => $userId,
                'role' => $role,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $resolver = new PrincipalResolver();

        expect($resolver->canManagePrincipal($principalId, $ownerId))->toBeTrue();
        expect($resolver->canManagePrincipal($principalId, $adminId))->toBeTrue();
        // A member can see the group's agents but must not be able to hand
        // out credentials that drive them.
        expect($resolver->canManagePrincipal($principalId, $memberId))->toBeFalse();
    });

    it('refuses a non-member of the group entirely', function (): void {
        $auth = bootAuthLayer();
        $ownerId = $auth->register('closed@example.com', 'Password1!', 'Closed');
        $outsiderId = $auth->register('outsider@example.com', 'Password1!', 'Outsider');

        $principalId = $this->makeGroupPrincipal($ownerId, 'Closed Group');

        expect((new PrincipalResolver())->canManagePrincipal($principalId, $outsiderId))->toBeFalse();
    });
});

describe('AgentApiKey relations', function (): void {
    uses(CreatesPrincipal::class);

    it('resolves its agent and its creator', function (): void {
        $auth = bootAuthLayer();
        $userId = $auth->register('rel@example.com', 'Password1!', 'Rel');
        simulateLoggedInSession($userId, 'rel@example.com');

        $agent = \Spora\Models\Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name' => 'Rel Agent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        [$key] = (new \Spora\Auth\AgentApiKeyIssuer())->issue((int) $agent->id, 'rel', $userId);

        expect($key->agent->id)->toBe($agent->id);
        expect($key->createdBy->id)->toBe($userId);
    });

    it('resolves a null creator rather than throwing', function (): void {
        $auth = bootAuthLayer();
        $userId = $auth->register('nc@example.com', 'Password1!', 'NC');
        simulateLoggedInSession($userId, 'nc@example.com');

        $agent = \Spora\Models\Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name' => 'NC Agent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        [$key] = (new \Spora\Auth\AgentApiKeyIssuer())->issue((int) $agent->id, 'nc', $userId);
        expect($key->createdBy->id)->toBe($userId);

        // `created_by` is `ON DELETE SET NULL`, so a revoked operator leaves
        // this shape: the key stays live and revokable, with nobody attached
        // to it. The list endpoint renders it like any other key.
        $key->created_by = null;
        $key->save();

        expect($key->fresh()->createdBy)->toBeNull();
    });

    it('resolves its chat and its key', function (): void {
        $seed = \Tests\Feature\AgentApi\AgentApiTestSupport::seedAgent('relcb');
        [$keyRow] = \Tests\Feature\AgentApi\AgentApiTestSupport::mintKey($seed['agentId'], 'relcb');

        $task = \Spora\Models\Task::create([
            'agent_id' => $seed['agentId'],
            'principal_id' => $seed['principalId'],
            'status' => 'QUEUED',
            'user_prompt' => 'hi',
        ]);

        $callback = \Spora\Models\AgentApiCallback::create([
            'chat_id' => $task->id,
            'key_id' => $keyRow->id,
            'agent_id' => $seed['agentId'],
            'url' => 'https://hooks.example.test/spora',
        ]);

        expect($callback->chat->id)->toBe($task->id);
        expect($callback->key->id)->toBe($keyRow->id);
    });

    it('drops a key\'s pending callbacks when the key is revoked', function (): void {
        $seed = \Tests\Feature\AgentApi\AgentApiTestSupport::seedAgent('cascade-key');
        [$keyRow] = \Tests\Feature\AgentApi\AgentApiTestSupport::mintKey($seed['agentId'], 'cascade-key');

        $task = \Spora\Models\Task::create([
            'agent_id' => $seed['agentId'],
            'principal_id' => $seed['principalId'],
            'status' => 'COMPLETED',
            'user_prompt' => 'hi',
        ]);
        \Spora\Models\AgentApiCallback::create([
            'chat_id' => $task->id,
            'key_id' => $keyRow->id,
            'agent_id' => $seed['agentId'],
            'url' => 'https://hooks.example.test/spora',
        ]);

        expect(\Spora\Models\AgentApiCallback::where('key_id', $keyRow->id)->count())->toBe(1);

        $keyRow->delete();

        // The dispatcher would otherwise keep trying to deliver to a
        // credential the operator has revoked.
        expect(\Spora\Models\AgentApiCallback::where('key_id', $keyRow->id)->count())->toBe(0);
    });

    it('drops an agent\'s keys with the agent', function (): void {
        $seed = \Tests\Feature\AgentApi\AgentApiTestSupport::seedAgent('cascade-agent');
        [$keyRow] = \Tests\Feature\AgentApi\AgentApiTestSupport::mintKey($seed['agentId'], 'cascade-agent');

        \Spora\Models\Agent::findOrFail($seed['agentId'])->delete();

        // A deleted agent must not leave a live credential behind.
        expect(\Spora\Models\AgentApiKey::where('id', $keyRow->id)->count())->toBe(0);
    });
});
