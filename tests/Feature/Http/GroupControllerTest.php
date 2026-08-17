<?php

declare(strict_types=1);

use Spora\Http\GroupController;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('GROUPCONTROLLER_TEST_PASSWORD') || define('GROUPCONTROLLER_TEST_PASSWORD', 'Password1!');

function makeGroupController(): array
{
    $auth = bootAuthLayer();
    $principalService = new PrincipalService(new PrincipalResolver());
    $groupService = new GroupService($principalService);
    $controller = new GroupController($auth, $groupService, $principalService);

    return [$controller, $auth, $groupService, $principalService];
}

describe('GroupController error paths', function (): void {
    it('returns 401 when no user is logged in for index', function (): void {
        [$controller] = makeGroupController();
        $response = $controller->index(jsonRequest('GET', '/api/v1/groups'));
        expect($response->getStatusCode())->toBe(401);
    });

    it('show returns 404 for a non-existent group', function (): void {
        [$controller, $auth] = makeGroupController();
        $uid = bootAuth($auth, 'gc3a@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($uid, 'gc3a@example.com');
        $response = $controller->show(999_999);
        expect($response->getStatusCode())->toBe(404);
    });

    it('store rejects empty name', function (): void {
        [$controller, $auth] = makeGroupController();
        $userId = bootAuth($auth, 'gc3b@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc3b@example.com');

        $response = $controller->store(jsonRequest('POST', '/api/v1/groups', [
            'name' => '   ',
        ]));
        expect($response->getStatusCode())->toBe(422);
    });

    it('store rejects malformed JSON', function (): void {
        [$controller, $auth] = makeGroupController();
        $userId = bootAuth($auth, 'gc3c@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc3c@example.com');

        $broken = Symfony\Component\HttpFoundation\Request::create(
            '/api/v1/groups',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{ not json',
        );
        $response = $controller->store($broken);
        expect($response->getStatusCode())->toBe(400);
    });

    it('update returns 404 for missing group', function (): void {
        [$controller, $auth] = makeGroupController();
        $userId = bootAuth($auth, 'gc3d@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc3d@example.com');

        $response = $controller->update(999_999, jsonRequest('PATCH', '/api/v1/groups/999999', []));
        expect($response->getStatusCode())->toBe(404);
    });

    it('update on malformed JSON returns 404 (no group found)', function (): void {
        // The update route looks up the group FIRST and returns 404 if not found.
        // Malformed JSON would only trigger if a group exists. Use a non-existent group ID
        // so the path short-circuits on the lookup — this guards that ordering.
        [$controller, $auth] = makeGroupController();
        $userId = bootAuth($auth, 'gc3e@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc3e@example.com');

        $broken = Symfony\Component\HttpFoundation\Request::create(
            '/api/v1/groups/999',
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{ bad json',
        );
        $response = $controller->update(999, $broken);
        expect($response->getStatusCode())->toBe(404);
    });

    it('update rejects empty name with 422', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc3f@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc3f@example.com');
        $group = $groupService->createGroup($userId, 'X');

        $response = $controller->update($group->id, jsonRequest('PATCH', '/api/v1/groups/' . $group->id, [
            'name' => '   ',
        ]));
        expect($response->getStatusCode())->toBe(422);
    });

    it('show returns 200 for an existing group the caller is admin of', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc3g@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        makeAdmin($auth, $userId);
        simulateLoggedInSession($userId, 'gc3g@example.com');
        $owner = bootAuth($auth, 'gc3g-owner@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        $group = $groupService->createGroup($owner, 'Hidden');

        $response = $controller->show($group->id);
        expect($response->getStatusCode())->toBe(200);
    });
});

describe('GroupController success paths', function (): void {
    it('store creates a group and returns 201', function (): void {
        [$controller, $auth] = makeGroupController();
        $userId = bootAuth($auth, 'gc4a@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc4a@example.com');

        $response = $controller->store(jsonRequest('POST', '/api/v1/groups', [
            'name' => 'NewOne',
            'description' => 'desc',
        ]));
        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['group']['name'])->toBe('NewOne');
        expect($body['data']['group']['caller_role'])->toBe('owner');
    });

    it('update renames a group', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc4b@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc4b@example.com');
        $group = $groupService->createGroup($userId, 'OldName');

        $response = $controller->update($group->id, jsonRequest('PATCH', '/api/v1/groups/' . $group->id, [
            'name' => 'NewName',
        ]));
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['group']['name'])->toBe('NewName');
    });

    it('destroy returns 200 when the group is owned and empty', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc4c@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc4c@example.com');
        $group = $groupService->createGroup($userId, 'D1');

        $response = $controller->destroy($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBe(true);
    });
});
