<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\SubAgentTool;

/**
 * `allowed_target_agents` is an intra-principal setting: it says which
 * agents *this principal* may delegate to. The setting belongs to the
 * agent, so it has to be resolved against the agent's principal.
 *
 * Resolving it through the paying owner instead lets the owner's
 * personal policy decide for a group agent: a target the group excluded
 * becomes reachable, and a target the group allowed becomes unreachable.
 */
function subAgentAllowlistConfigService(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('subagent-allowlist'),
        [SubAgentTool::class],
    );
}

/**
 * A group with an owner and a plain member, and two agents on the
 * group's principal.
 *
 * @return array{groupPrincipalId: int, ownerId: int, memberId: int, sourceAgentId: int, targetAgentId: int}
 */
function subAgentAllowlistGroupFixture(): array
{
    $auth = bootAuthLayer();
    $ownerId = $auth->register('allowlist-owner@example.com', 'Password1!', 'Owner');
    $memberId = $auth->register('allowlist-member@example.com', 'Password1!', 'Member');

    $now = date('Y-m-d H:i:s');
    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'              => 'AllowlistGroup',
        'created_by_user_id' => $ownerId,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $ownerId,
        'role'       => GroupMembership::ROLE_OWNER,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $memberId,
        'role'       => GroupMembership::ROLE_MEMBER,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $groupPrincipalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_GROUP,
        'group_id'   => $groupId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $sourceAgentId = (int) Agent::create([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Group Source',
        'max_steps'    => 5,
        'is_active'    => true,
    ])->id;
    $targetAgentId = (int) Agent::create([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Group Target',
        'max_steps'    => 5,
        'is_active'    => true,
    ])->id;

    return [
        'groupPrincipalId' => $groupPrincipalId,
        'ownerId'          => $ownerId,
        'memberId'         => $memberId,
        'sourceAgentId'    => $sourceAgentId,
        'targetAgentId'    => $targetAgentId,
    ];
}

it('refuses a target the group excluded even when the paying owner allows it', function (): void {
    $fixture = subAgentAllowlistGroupFixture();
    $config = subAgentAllowlistConfigService();

    // The group's policy: this group delegates to nobody.
    $config->putPrincipalSettings(SubAgentTool::class, $fixture['groupPrincipalId'], [
        'allowed_target_agents' => [],
    ]);

    // The paying owner's personal policy: the target is on it.
    $config->putPrincipalSettings(SubAgentTool::class, createUserPrincipalPublic($fixture['ownerId']), [
        'allowed_target_agents' => [$fixture['targetAgentId']],
    ]);

    $handover = Mockery::mock(HandoverServiceInterface::class);
    $handover->allows('handover')->andReturn(Task::create([
        'principal_id'    => $fixture['groupPrincipalId'],
        'trigger_user_id' => $fixture['memberId'],
        'agent_id'        => $fixture['targetAgentId'],
        'status'          => 'QUEUED',
        'user_prompt'     => 'go',
        'max_steps'       => 5,
    ]));
    $subAgent = Mockery::mock(Spora\Services\SubAgentServiceInterface::class);
    $subAgent->allows('spawn')->andReturn(Task::create([
        'principal_id'    => $fixture['groupPrincipalId'],
        'trigger_user_id' => $fixture['memberId'],
        'agent_id'        => $fixture['targetAgentId'],
        'status'          => 'QUEUED',
        'user_prompt'     => 'go',
        'max_steps'       => 5,
    ]));

    $tool = new SubAgentTool($handover, $subAgent, $config);

    $task = Task::create([
        'principal_id'    => $fixture['groupPrincipalId'],
        'trigger_user_id' => $fixture['memberId'],
        'agent_id'        => $fixture['sourceAgentId'],
        'status'          => 'RUNNING',
        'user_prompt'     => 'delegate please',
        'max_steps'       => 5,
    ]);

    $context = new PrincipalContext(
        principalId: $fixture['groupPrincipalId'],
        type: Principal::TYPE_GROUP,
        ownerUserId: $fixture['ownerId'],
        runnerUserId: $fixture['memberId'],
    );

    $result = $tool->execute(
        arguments: ['op' => 'handover', 'target_agent_id' => $fixture['targetAgentId'], 'prompt' => 'go'],
        agentId: $fixture['sourceAgentId'],
        taskId: $task->id,
        context: $context,
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not in the allowed_target_agents list');
});

it('accepts a target the group allows even when the paying owner does not', function (): void {
    $fixture = subAgentAllowlistGroupFixture();
    $config = subAgentAllowlistConfigService();

    $config->putPrincipalSettings(SubAgentTool::class, $fixture['groupPrincipalId'], [
        'allowed_target_agents' => [$fixture['targetAgentId']],
    ]);

    // The owner's personal policy does not name the target. The group's
    // does, and the group is what the agent belongs to.
    $config->putPrincipalSettings(SubAgentTool::class, createUserPrincipalPublic($fixture['ownerId']), [
        'allowed_target_agents' => [],
    ]);

    $handover = Mockery::mock(HandoverServiceInterface::class);
    $handover->allows('handover')->andReturn(Task::create([
        'principal_id'    => $fixture['groupPrincipalId'],
        'trigger_user_id' => $fixture['memberId'],
        'agent_id'        => $fixture['targetAgentId'],
        'status'          => 'QUEUED',
        'user_prompt'     => 'go',
        'max_steps'       => 5,
    ]));

    $subAgent = Mockery::mock(Spora\Services\SubAgentServiceInterface::class);
    $subAgent->shouldNotReceive('spawn');

    $tool = new SubAgentTool($handover, $subAgent, $config);

    $task = Task::create([
        'principal_id'    => $fixture['groupPrincipalId'],
        'trigger_user_id' => $fixture['memberId'],
        'agent_id'        => $fixture['sourceAgentId'],
        'status'          => 'RUNNING',
        'user_prompt'     => 'delegate please',
        'max_steps'       => 5,
    ]);

    $context = new PrincipalContext(
        principalId: $fixture['groupPrincipalId'],
        type: Principal::TYPE_GROUP,
        ownerUserId: $fixture['ownerId'],
        runnerUserId: $fixture['memberId'],
    );

    $result = $tool->execute(
        arguments: ['op' => 'handover', 'target_agent_id' => $fixture['targetAgentId'], 'prompt' => 'go'],
        agentId: $fixture['sourceAgentId'],
        taskId: $task->id,
        context: $context,
    );

    expect($result->success)->toBeTrue("Tool rejected a group-allowed target: {$result->content}");
});
