<?php

declare(strict_types=1);

use Spora\Http\GroupMemberController;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\UserService;

defined('GMC_TEST_PASSWORD') || define('GMC_TEST_PASSWORD', 'Password1!');

function makeGroupMemberController(): array
{
    $auth = bootAuthLayer();
    $principalService = new PrincipalService(new PrincipalResolver());
    $groupService = new GroupService($principalService);
    $userService = new UserService();
    $controller = new GroupMemberController($auth, $groupService, $userService);

    return [$controller, $auth, $groupService, $principalService, $userService];
}

describe('GroupMemberController', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('returns 401 on index when not authenticated', function (): void {
        [$controller] = makeGroupMemberController();
        $response = $controller->index(1);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 404 for a non-existent group on index', function (): void {
        [$controller, $auth] = makeGroupMemberController();
        $userId = bootAuth($auth, 'gmc1a@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gmc1a@example.com');
        $response = $controller->index(999_999);
        expect($response->getStatusCode())->toBe(404);
    });

    it('lists members for an owner', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $userId = bootAuth($auth, 'gmc1b@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gmc1b@example.com');
        $group = $groupService->createGroup($userId, 'Gx');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect(count($body['data']['members']))->toBe(1);
        expect($body['data']['members'][0]['role'])->toBe('owner');
    });

    it('index returns the user name and email for each member', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc1b-name@example.com', GMC_TEST_PASSWORD, 'Owner Name');
        simulateLoggedInSession($ownerId, 'gmc1b-name@example.com');
        $group = $groupService->createGroup($ownerId, 'Name Email');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['members'][0])->toHaveKeys(['user_id', 'role', 'joined_at', 'name', 'email']);
        expect($body['data']['members'][0]['name'])->toBe('Owner Name');
        expect($body['data']['members'][0]['email'])->toBe('gmc1b-name@example.com');
    });

    it('index returns members for a plain group member (regression for stale-cache-group bug)', function (): void {
        // Group + principal + owner
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc-memb-owner@example.com', GMC_TEST_PASSWORD, 'Owner User');
        $group  = $groupService->createGroup($ownerId, 'GroupForMembers');

        // Add a plain member (not owner / not admin)
        $memberId = bootAuth($auth, 'gmc-memb-plain@example.com', GMC_TEST_PASSWORD, 'Plain Member');
        $groupService->addMember((int) $group->id, (int) $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);

        // Sign in as that plain member and ask for the membership list
        simulateLoggedInSession($memberId, 'gmc-memb-plain@example.com');
        $response = $controller->index((int) $group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect(count($body['data']['members']))->toBe(2);
        $roles = array_column($body['data']['members'], 'role');
        expect($roles)->toContain('owner');
        expect($roles)->toContain('member');
    });

    it('index still returns 404 for a non-member (caller has zero principal rows for the group)', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc-memb-stranger-owner@example.com', GMC_TEST_PASSWORD);
        $group  = $groupService->createGroup($ownerId, 'StrangerTest');

        $strangerId = bootAuth($auth, 'gmc-memb-stranger@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($strangerId, 'gmc-memb-stranger@example.com');

        $response = $controller->index((int) $group->id);
        expect($response->getStatusCode())->toBe(404);
    });

    it('returns 401 on store when not authenticated', function (): void {
        [$controller] = makeGroupMemberController();
        $request = jsonRequest('POST', '/api/v1/groups/1/members', ['user_id' => 1, 'role' => 'member']);
        $response = $controller->store(1, $request);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 on update when not authenticated', function (): void {
        [$controller] = makeGroupMemberController();
        $request = jsonRequest('PATCH', '/api/v1/groups/1/members/2', ['role' => 'admin']);
        $response = $controller->update(1, 2, $request);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 on destroy when not authenticated', function (): void {
        [$controller] = makeGroupMemberController();
        $response = $controller->destroy(1, 2);
        expect($response->getStatusCode())->toBe(401);
    });

    it('update returns 404 for missing group', function (): void {
        [$controller, $auth] = makeGroupMemberController();
        $userId = bootAuth($auth, 'gmc1c@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gmc1c@example.com');
        $request = jsonRequest('PATCH', '/api/v1/groups/999/members/1', ['role' => 'admin']);
        $response = $controller->update(999, 1, $request);
        expect($response->getStatusCode())->toBe(404);
    });

    it('destroy returns 404 for missing group', function (): void {
        [$controller, $auth] = makeGroupMemberController();
        $userId = bootAuth($auth, 'gmc1d@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gmc1d@example.com');
        $response = $controller->destroy(999, 1);
        expect($response->getStatusCode())->toBe(404);
    });

    it('store returns 404 for missing group', function (): void {
        [$controller, $auth] = makeGroupMemberController();
        $userId = bootAuth($auth, 'gmc1e@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gmc1e@example.com');
        $request = jsonRequest('POST', '/api/v1/groups/999/members', ['user_id' => 1, 'role' => 'member']);
        $response = $controller->store(999, $request);
        expect($response->getStatusCode())->toBe(404);
    });

    it('update with malformed JSON returns 404 (group not found path first)', function (): void {
        [$controller, $auth] = makeGroupMemberController();
        $userId = bootAuth($auth, 'gmc1f@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gmc1f@example.com');
        $broken = Symfony\Component\HttpFoundation\Request::create(
            '/api/v1/groups/999/members/1',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{ not json',
        );
        $response = $controller->update(999, 1, $broken);
        expect($response->getStatusCode())->toBe(404);
    });

    it('store rejects invalid role with 422', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $userId = bootAuth($auth, 'gmc1g@example.com', GMC_TEST_PASSWORD);
        $target = bootAuth($auth, 'gmc1g-target@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gmc1g@example.com');
        $group = $groupService->createGroup($userId, 'Roley');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'user_id' => $target,
            'role' => 'superuser',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(422);
    });

    it('store adds a member and returns 201', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2a@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OA');
        $target = bootAuth($auth, 'gmc2a-target@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($ownerId, 'gmc2a@example.com');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'user_id' => $target,
            'role'    => 'member',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['member']['user_id'])->toBe($target);
        expect($body['data']['member']['role'])->toBe('member');
    });

    it('store defaults the role to member when role is missing', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2b@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OB');
        $target = bootAuth($auth, 'gmc2b-target@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($ownerId, 'gmc2b@example.com');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'user_id' => $target,
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['member']['role'])->toBe('member');
    });

    it('store rejects missing user_id with 422', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2c@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OC');
        simulateLoggedInSession($ownerId, 'gmc2c@example.com');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', []);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(422);
    });

    it('store resolves email to user_id and returns 201', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc-email1@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'EmailAdd');
        $targetId = bootAuth($auth, 'gmc-email1-target@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($ownerId, 'gmc-email1@example.com');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'email' => 'gmc-email1-target@example.com',
            'role'  => 'member',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['member']['user_id'])->toBe($targetId);
        expect($body['data']['member']['role'])->toBe('member');
    });

    it('store email lookup is case-insensitive', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc-email2@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'EmailAddCI');
        $targetId = bootAuth($auth, 'gmc-email2-target@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($ownerId, 'gmc-email2@example.com');

        // Caller wrote the email in mixed case + whitespace; should still match.
        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'email' => '  GMC-Email2-Target@Example.COM  ',
            'role'  => 'admin',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['member']['user_id'])->toBe($targetId);
        expect($body['data']['member']['role'])->toBe('admin');
    });

    it('store returns 404 when no user has the email', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc-email3@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'EmailAddMiss');
        simulateLoggedInSession($ownerId, 'gmc-email3@example.com');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'email' => 'no-such-user@example.com',
            'role'  => 'member',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(404);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('USER_NOT_FOUND');
    });

    it('store rejects malformed email with 422', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc-email4@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'EmailAddBad');
        simulateLoggedInSession($ownerId, 'gmc-email4@example.com');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'email' => 'not-an-email',
            'role'  => 'member',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(422);
    });

    it('store rejects when both user_id and email are provided', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc-email5@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'EmailAddBoth');
        $targetId = bootAuth($auth, 'gmc-email5-target@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($ownerId, 'gmc-email5@example.com');

        // Mutually exclusive — caller must pick one or the other. Resolving
        // both ambiguously would risk surprising the operator with the wrong
        // user, so we 422 instead.
        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'user_id' => $targetId,
            'email'   => 'gmc-email5-target@example.com',
            'role'    => 'member',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(422);
    });

    it('store rejects malformed JSON with 400', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2d@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OD');
        simulateLoggedInSession($ownerId, 'gmc2d@example.com');

        $broken = Symfony\Component\HttpFoundation\Request::create(
            '/api/v1/groups/' . $group->id . '/members',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{ not json',
        );
        $response = $controller->store($group->id, $broken);
        expect($response->getStatusCode())->toBe(400);
    });

    it('store returns 403 for a non-owner caller', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2e@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OE');
        $strangerId = bootAuth($auth, 'gmc2e-other@example.com', GMC_TEST_PASSWORD);
        $target = bootAuth($auth, 'gmc2e-target@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($strangerId, 'gmc2e-other@example.com');

        $request = jsonRequest('POST', '/api/v1/groups/' . $group->id . '/members', [
            'user_id' => $target,
            'role'    => 'member',
        ]);
        $response = $controller->store($group->id, $request);
        expect($response->getStatusCode())->toBe(403);
    });

    it('index returns 404 for a group the caller cannot see', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2f@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OF');
        $strangerId = bootAuth($auth, 'gmc2f-other@example.com', GMC_TEST_PASSWORD);
        simulateLoggedInSession($strangerId, 'gmc2f-other@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(404);
    });

    it('index returns members for an admin caller', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2g@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OG');
        $adminId = bootAuth($auth, 'gmc2g-admin@example.com', GMC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);
        simulateLoggedInSession($adminId, 'gmc2g-admin@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['members'])->not->toBeEmpty();
    });

    it('update changes a member role and returns 200', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2h@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OH');
        $target = bootAuth($auth, 'gmc2h-target@example.com', GMC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, (int) $target, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        simulateLoggedInSession($ownerId, 'gmc2h@example.com');

        $request = jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/members/' . $target, [
            'role' => 'admin',
        ]);
        $response = $controller->update((int) $group->id, (int) $target, $request);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['member']['role'])->toBe('admin');
    });

    it('update rejects invalid role with 422', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2i@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OI');
        $target = bootAuth($auth, 'gmc2i-target@example.com', GMC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, (int) $target, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        simulateLoggedInSession($ownerId, 'gmc2i@example.com');

        $request = jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/members/' . $target, [
            'role' => 'joker',
        ]);
        $response = $controller->update((int) $group->id, (int) $target, $request);
        expect($response->getStatusCode())->toBe(422);
    });

    it('update rejects missing role with 422', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2j@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OJ');
        $target = bootAuth($auth, 'gmc2j-target@example.com', GMC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, (int) $target, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        simulateLoggedInSession($ownerId, 'gmc2j@example.com');

        $request = jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/members/' . $target, []);
        $response = $controller->update((int) $group->id, (int) $target, $request);
        expect($response->getStatusCode())->toBe(422);
    });

    it('update returns 403 for a non-owner caller', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2k@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OK');
        $strangerId = bootAuth($auth, 'gmc2k-other@example.com', GMC_TEST_PASSWORD);
        $target = bootAuth($auth, 'gmc2k-target@example.com', GMC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, (int) $target, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        simulateLoggedInSession($strangerId, 'gmc2k-other@example.com');

        $request = jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/members/' . $target, [
            'role' => 'admin',
        ]);
        $response = $controller->update((int) $group->id, (int) $target, $request);
        expect($response->getStatusCode())->toBe(403);
    });

    it('destroy removes a member and returns 200', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2l@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OL');
        $target = bootAuth($auth, 'gmc2l-target@example.com', GMC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, (int) $target, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        simulateLoggedInSession($ownerId, 'gmc2l@example.com');

        $response = $controller->destroy((int) $group->id, (int) $target);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBe(true);
    });

    it('destroy returns 403 for a non-owner caller', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2m@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'OM');
        $strangerId = bootAuth($auth, 'gmc2m-other@example.com', GMC_TEST_PASSWORD);
        $target = bootAuth($auth, 'gmc2m-target@example.com', GMC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, (int) $target, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);
        simulateLoggedInSession($strangerId, 'gmc2m-other@example.com');

        $response = $controller->destroy((int) $group->id, (int) $target);
        expect($response->getStatusCode())->toBe(403);
    });

    it('destroy returns 409 for ROLE_RULE_VIOLATION when demoting the last owner', function (): void {
        [$controller, $auth, $groupService] = makeGroupMemberController();
        $ownerId = bootAuth($auth, 'gmc2n@example.com', GMC_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'ON');
        simulateLoggedInSession($ownerId, 'gmc2n@example.com');

        $response = $controller->destroy((int) $group->id, (int) $ownerId);
        expect($response->getStatusCode())->toBe(409);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('ROLE_RULE_VIOLATION');
    });
});
