<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Principal;
use Spora\Models\Task;
use Spora\Models\ToolCall;

const AGENT_TEST_PASSWORD = 'Password1!';

it('uses the agents table', function (): void {
    $agent = new Agent();

    expect($agent->getTable())->toBe('agents');
});

it('casts boolean and integer fields', function (): void {
    $userId = bootAuthLayer()->register('agent-cast@example.com', AGENT_TEST_PASSWORD, 'Agent');

    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'                 => 'Cast Agent',
        'llm_provider'         => 'mock',
        'llm_model'            => 'mock',
        'max_steps'            => 7,
        'is_active'            => true,
        'allow_followup'       => true,
    ]);

    expect($agent->is_active)->toBeBool()
        ->and($agent->getAttribute('allow_followup'))->toBeBool()
        ->and($agent->max_steps)->toBeInt();
});

it('belongs to a principal', function (): void {
    $userId = bootAuthLayer()->register('agent-principal@example.com', AGENT_TEST_PASSWORD, 'AgentPrincipal');
    $principalId = $this->createUserPrincipal($userId);
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Owner Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    expect($agent->principal)->toBeInstanceOf(Principal::class)
        ->and((int) $agent->principal->getKey())->toBe($principalId);
});

it('has many tasks, agent tools, and tool calls', function (): void {
    $userId = bootAuthLayer()->register('agent-hasmany@example.com', AGENT_TEST_PASSWORD, 'HasMany');
    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'HasMany Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    Task::create([
        'agent_id'    => $agent->id,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'hi',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);
    AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Spora\Tools\StubOutputTool',
        'tool_name'  => 'stub_output',
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'hi',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);
    ToolCall::create([
        'task_id'             => $task->id,
        'agent_id'            => $agent->id,
        'provider_call_id'    => 'orphan_call',
        'tool_name'           => 'stub_output',
        'tool_class'          => 'StubOutputTool',
        'tool_type'           => 'function',
        'operation'           => 'echo',
        'operation_description' => 'Echo',
        'status'              => 'PENDING',
        'proposed_arguments'  => [],
    ]);

    expect($agent->tasks)->toHaveCount(2)
        ->and($agent->agentTools)->toHaveCount(1)
        ->and($agent->toolCalls)->toHaveCount(1);
});

it('legacy user_id accessor resolves the user-principal owner', function (): void {
    $userId = bootAuthLayer()->register('agent-legacyuid@example.com', AGENT_TEST_PASSWORD, 'Legacy');
    $principalId = $this->createUserPrincipal($userId);
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Legacy User Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    expect($agent->user_id)->toBe($userId);
});

it('legacy user_id accessor falls back to the first group owner for a group-principal', function (): void {
    $ownerId = bootAuthLayer()->register('agent-groupowner@example.com', AGENT_TEST_PASSWORD, 'Owner');
    $groupId = (int) Illuminate\Database\Capsule\Manager::table('groups')->insertGetId([
        'name'              => 'CoverageGroup',
        'created_by_user_id' => $ownerId,
        'created_at'        => date('Y-m-d H:i:s'),
        'updated_at'        => date('Y-m-d H:i:s'),
    ]);
    $principalId = (int) Illuminate\Database\Capsule\Manager::table('principals')->insertGetId([
        'type'       => 'group',
        'group_id'   => $groupId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Illuminate\Database\Capsule\Manager::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $ownerId,
        'role'       => 'owner',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Group Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    expect($agent->user_id)->toBe($ownerId);
});

it('legacy user_id accessor returns null when the principal is missing', function (): void {
    $agent = new Agent();
    $agent->id = 12345;
    $agent->principal_id = 999999;

    expect($agent->user_id)->toBeNull();
});

it('legacy user_id accessor returns null when the group has no owner', function (): void {
    $ownerId = bootAuthLayer()->register('agent-orphanowner@example.com', AGENT_TEST_PASSWORD, 'OrphanOwner');
    $groupId = (int) Illuminate\Database\Capsule\Manager::table('groups')->insertGetId([
        'name'              => 'OrphanGroup',
        'created_by_user_id' => $ownerId,
        'created_at'        => date('Y-m-d H:i:s'),
        'updated_at'        => date('Y-m-d H:i:s'),
    ]);
    $principalId = (int) Illuminate\Database\Capsule\Manager::table('principals')->insertGetId([
        'type'       => 'group',
        'group_id'   => $groupId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $agent = new Agent();
    $agent->id = 22222;
    $agent->principal_id = $principalId;

    expect($agent->user_id)->toBeNull();
});

it('legacy user attribute returns the resolved user via the principal', function (): void {
    $userId = bootAuthLayer()->register('agent-legacyuser@example.com', AGENT_TEST_PASSWORD, 'LegacyUser');
    $principalId = $this->createUserPrincipal($userId);
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Legacy User Attr',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $user = $agent->user();
    $loaded = $user === null ? null : $user->first();
    expect($loaded)->toBeInstanceOf(Spora\Models\User::class)
        ->and((int) $loaded->getKey())->toBe($userId);
});

it('legacy user attribute returns null when the principal is missing', function (): void {
    $agent = new Agent();
    $agent->id = 33333;
    $agent->principal_id = 999999;

    expect($agent->user())->toBeNull();
});

it('user() resolves the owner user when the principal is a group with an owner', function (): void {
    // Two users, two owner memberships. `Agent::user()` picks the
    // lowest-id owner — the first user inserted gets the lower id on a
    // fresh AUTO_INCREMENT counter, so the test asserts `min($a, $b)`.
    $userA    = bootAuthLayer()->register('agent-grpowner-a@example.com', AGENT_TEST_PASSWORD, 'OwnerA');
    $userB    = bootAuthLayer()->register('agent-grpowner-b@example.com', AGENT_TEST_PASSWORD, 'OwnerB');
    $expected = min($userA, $userB);

    // `makeGroupPrincipal` inserts the creator as a `member`, so promote
    // both users to owner explicitly to make the test deterministic.
    $groupPrincipalId = $this->makeGroupPrincipal($userA, 'OwnedGroup');
    $groupId = (int) Illuminate\Database\Capsule\Manager::table('principals')
        ->where('id', $groupPrincipalId)->value('group_id');
    Illuminate\Database\Capsule\Manager::table('group_memberships')
        ->where('group_id', $groupId)
        ->update(['role' => Spora\Models\GroupMembership::ROLE_OWNER]);
    Illuminate\Database\Capsule\Manager::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $userB,
        'role'       => Spora\Models\GroupMembership::ROLE_OWNER,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $agent = Agent::create([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Group Owned Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $user = $agent->user();
    expect($user)->not->toBeNull();
    $loaded = $user->first();
    expect($loaded)->toBeInstanceOf(Spora\Models\User::class)
        ->and((int) $loaded->getKey())->toBe($expected);
});

it('user() returns null when the principal is a group with no owner', function (): void {
    $creatorId = bootAuthLayer()->register('agent-grpcreator@example.com', AGENT_TEST_PASSWORD, 'GroupCreator');
    // Group with a creator-as-member only — no owner membership.
    $groupPrincipalId = $this->makeGroupPrincipal($creatorId, 'OwnerlessGroup');
    Illuminate\Database\Capsule\Manager::table('group_memberships')
        ->where('group_id', Illuminate\Database\Capsule\Manager::table('principals')
            ->where('id', $groupPrincipalId)->value('group_id'))
        ->update(['role' => Spora\Models\GroupMembership::ROLE_MEMBER]);

    $agent = Agent::create([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Ownerless Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    expect($agent->user())->toBeNull();
});

it('getUserAttribute returns the resolved User instance', function (): void {
    $userId = bootAuthLayer()->register('agent-getuserattr@example.com', AGENT_TEST_PASSWORD, 'GetUserAttr');
    $principalId = $this->createUserPrincipal($userId);
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Attr Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $attr = $agent->user;
    expect($attr)->toBeInstanceOf(Spora\Models\User::class)
        ->and((int) $attr->getKey())->toBe($userId);
});

it('getUserAttribute returns null when the principal is missing', function (): void {
    $agent = new Agent();
    $agent->id = 44444;
    $agent->principal_id = 999998;

    expect($agent->user)->toBeNull();
});
