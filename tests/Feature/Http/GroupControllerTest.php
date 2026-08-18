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
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('returns 401 when no user is logged in for index', function (): void {
        [$controller] = makeGroupController();
        $response = $controller->index();
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
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

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

    it('index returns 200 with caller\'s groups for a non-admin member', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc5a@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5a@example.com');
        $groupService->createGroup($userId, 'Mine');

        $response = $controller->index();
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['groups'])->not->toBeEmpty();
        expect(array_column($body['data']['groups'], 'name'))->toContain('Mine');
    });

    it('index returns 200 with all groups for an admin caller', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $adminId = bootAuth($auth, 'gc5b@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        makeAdmin($auth, $adminId);
        simulateLoggedInSession($adminId, 'gc5b@example.com');
        $ownerId = bootAuth($auth, 'gc5b-owner@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        $groupService->createGroup($ownerId, 'Elsewhere');

        $response = $controller->index();
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect(array_column($body['data']['groups'], 'name'))->toContain('Elsewhere');
    });

    it('show returns 404 for a group the caller cannot see', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $ownerId = bootAuth($auth, 'gc5c-owner@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'Private');
        $callerId = bootAuth($auth, 'gc5c@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($callerId, 'gc5c@example.com');

        $response = $controller->show($group->id);
        expect($response->getStatusCode())->toBe(404);
    });

    it('update accepts a description change and returns 200', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc5d@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5d@example.com');
        $group = $groupService->createGroup($userId, 'D2');

        $response = $controller->update($group->id, jsonRequest('PATCH', '/api/v1/groups/' . $group->id, [
            'description' => 'fresh desc',
        ]));
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['group']['description'])->toBe('fresh desc');
    });

    it('update treats empty description as null', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc5e@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5e@example.com');
        $group = $groupService->createGroup($userId, 'D3');

        $response = $controller->update($group->id, jsonRequest('PATCH', '/api/v1/groups/' . $group->id, [
            'description' => '   ',
        ]));
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['group']['description'])->toBeNull();
    });

    it('update accepts explicit null description to clear it', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc5f@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5f@example.com');
        $group = $groupService->createGroup($userId, 'D4', 'initial');

        $response = $controller->update($group->id, jsonRequest('PATCH', '/api/v1/groups/' . $group->id, [
            'description' => null,
        ]));
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['group']['description'])->toBeNull();
    });

    it('update with empty body returns the unchanged group at 200', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $userId = bootAuth($auth, 'gc5g@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5g@example.com');
        $group = $groupService->createGroup($userId, 'D5');

        $response = $controller->update($group->id, jsonRequest('PATCH', '/api/v1/groups/' . $group->id, []));
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['group']['name'])->toBe('D5');
    });

    it('store accepts a null description and stores it as null', function (): void {
        [$controller, $auth] = makeGroupController();
        $userId = bootAuth($auth, 'gc5h@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5h@example.com');

        $response = $controller->store(jsonRequest('POST', '/api/v1/groups', [
            'name'        => 'ND',
            'description' => null,
        ]));
        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['group']['description'])->toBeNull();
    });

    it('store rejects missing name with 422', function (): void {
        [$controller, $auth] = makeGroupController();
        $userId = bootAuth($auth, 'gc5i@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5i@example.com');

        $response = $controller->store(jsonRequest('POST', '/api/v1/groups', [
            'description' => 'no name',
        ]));
        expect($response->getStatusCode())->toBe(422);
    });

    it('destroy returns 403 for a non-owner caller', function (): void {
        [$controller, $auth, $groupService] = makeGroupController();
        $ownerId = bootAuth($auth, 'gc5j@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        $group = $groupService->createGroup($ownerId, 'Foreign');
        $otherId = bootAuth($auth, 'gc5j-other@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($otherId, 'gc5j-other@example.com');

        $response = $controller->destroy($group->id);
        expect($response->getStatusCode())->toBe(403);
    });

    it('destroy returns 409 with reassign_endpoint when the group still owns agents', function (): void {
        [$controller, $auth, $groupService, $ps] = makeGroupController();
        $userId = bootAuth($auth, 'gc5k@example.com', GROUPCONTROLLER_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gc5k@example.com');
        $group = $groupService->createGroup($userId, 'Busy');
        $ps->ensureGroupPrincipal((int) $group->id);
        Illuminate\Database\Capsule\Manager::table('agents')->insert([
            'principal_id'           => (int) $ps->principalForGroup((int) $group->id)->id,
            'name'                   => 'Still Attached',
            'description'            => null,
            'llm_driver_config_id'   => null,
            'max_steps'              => 10,
            'is_active'              => 1,
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        $response = $controller->destroy($group->id);
        expect($response->getStatusCode())->toBe(409);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('GROUP_HAS_AGENTS');
        expect($body['error']['agent_ids'])->not->toBeEmpty();
        expect($body['error']['reassign_endpoint'])->toBe('/api/v1/agents/{id}/transfer');
    });

    it('destroy returns 401 when no user is logged in', function (): void {
        [$controller] = makeGroupController();
        $response = $controller->destroy(1);
        expect($response->getStatusCode())->toBe(401);
    });
});
