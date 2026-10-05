<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\PrincipalResolver;

defined('AGENT_TEST_PASSWORD') || define('AGENT_TEST_PASSWORD', 'Password1!');

/**
 * `ToolInterface::execute()`'s third parameter, `$userId`, is populated by
 * `Orchestrator::safeExecute()` as `$callingAgent->user_id`, and
 * `$context->ownerUserId` comes from `PrincipalResolver::ownerUserId()`. These
 * read like two different identities — the agent row's `user_id` column versus
 * the principal's owner — and that reading is wrong.
 *
 * Migration 0067 dropped the `agents.user_id` column. `Agent::user_id` is now a
 * read-only accessor whose whole body is
 * `PrincipalResolver::ownerUserId($this->principal_id)`, and
 * `resolveForToolExecute()` calls that same resolver with the same argument
 * (its `$principal` was fetched by `$agent->principal_id`, so `$principal->id`
 * *is* `$agent->principal_id`). The two are the same value by construction.
 *
 * This matters because the legacy parameter is being retired, and a reviewer
 * cannot check that by reading `Orchestrator` alone — `$callingAgent->user_id`
 * looks like a column read. So it is pinned here, for every branch where the two
 * could plausibly differ, including the group case that looks most suspicious.
 */
it('resolves the same value for a user principal', function (): void {
    bootAuthLayer();
    $userId = bootAuthLayer()->register('legacy-user@example.com', AGENT_TEST_PASSWORD, 'Owner');

    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_USER,
        'user_id'    => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'User Principal Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $legacy   = Agent::find($agent->id)->user_id;
    $resolved = (new PrincipalResolver())->resolveForToolExecute($agent->id)->ownerUserId;

    expect($legacy)->toBe($userId)->and($resolved)->toBe($legacy);
});

/**
 * The case that reads as a divergence: a group whose owner is one user while a
 * different member is the one associated with the agent. There is no column
 * that could hold the member, so no divergence is expressible.
 */
it('resolves the same value for a group principal, and it is the group owner', function (): void {
    bootAuthLayer();
    $app    = bootAuthLayer();
    $owner  = $app->register('group-owner@example.com', AGENT_TEST_PASSWORD, 'Owner');
    $member = $app->register('group-member@example.com', AGENT_TEST_PASSWORD, 'Member');

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'              => 'EquivalenceGroup',
        'created_by_user_id' => $owner,
        'created_at'        => date('Y-m-d H:i:s'),
        'updated_at'        => date('Y-m-d H:i:s'),
    ]);
    // The owner is inserted first, so "first owner membership" is unambiguous.
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $owner,
        'role'       => GroupMembership::ROLE_OWNER,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $member,
        'role'       => GroupMembership::ROLE_MEMBER,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_GROUP,
        'group_id'   => $groupId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Group Principal Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $legacy   = Agent::find($agent->id)->user_id;
    $resolved = (new PrincipalResolver())->resolveForToolExecute($agent->id)->ownerUserId;

    expect($legacy)->toBe($owner)
        ->and($resolved)->toBe($legacy)
        ->and($resolved)->not->toBe($member);
});

it('resolves null on both sides when the agent row does not exist', function (): void {
    bootAuthLayer();

    $resolved = (new PrincipalResolver())->resolveForToolExecute(999_999_999);

    // The orchestrator leaves `$userId` null when `getAgentByAgentId()` misses.
    $legacy = null;

    expect($legacy)->toBeNull()->and($resolved->ownerUserId)->toBeNull();
});

/**
 * The dangling case: `agents.principal_id` RESTRICTs to `principals`, so the
 * principal has to be deleted rather than never created. Both resolvers miss and
 * both yield null — which is why `$context->ownerUserId ?? $userId` never rescues
 * anything: when the context is null the legacy value is null too.
 */
it('resolves null on both sides when the principal row is gone', function (): void {
    bootAuthLayer();
    $userId = bootAuthLayer()->register('dangling@example.com', AGENT_TEST_PASSWORD, 'Owner');

    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_USER,
        'user_id'    => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Dangling Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $driver = Capsule::connection()->getDriverName();
    if ($driver === 'sqlite') {
        Capsule::statement('PRAGMA defer_foreign_keys = ON');
    } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
        Capsule::statement('SET FOREIGN_KEY_CHECKS=0');
    }
    Capsule::table('principals')->where('id', $principalId)->delete();

    $legacy   = Agent::find($agent->id)->user_id;
    $context  = (new PrincipalResolver())->resolveForToolExecute($agent->id);

    expect($legacy)->toBeNull()->and($context->ownerUserId)->toBeNull();
});
