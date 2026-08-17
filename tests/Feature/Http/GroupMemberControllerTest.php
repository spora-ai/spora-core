<?php

declare(strict_types=1);

use Spora\Http\GroupMemberController;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('GMC_TEST_PASSWORD') || define('GMC_TEST_PASSWORD', 'Password1!');

function makeGroupMemberController(): array
{
    $auth = bootAuthLayer();
    $principalService = new PrincipalService(new PrincipalResolver());
    $groupService = new GroupService($principalService);
    $controller = new GroupMemberController($auth, $groupService);

    return [$controller, $auth, $groupService, $principalService];
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
});
