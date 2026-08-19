<?php

declare(strict_types=1);

use Spora\Http\GroupPreferencesController;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('GP_TEST_PASSWORD') || define('GP_TEST_PASSWORD', 'Password1!');

function makeGroupPreferencesController(): array
{
    $auth = bootAuthLayer();
    $principalService = new PrincipalService(new PrincipalResolver());
    $controller = new GroupPreferencesController($auth, $principalService);

    return [$controller, $auth, $principalService];
}

describe('GroupPreferencesController', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('returns 401 when no user is logged in for show', function (): void {
        [$controller] = makeGroupPreferencesController();
        $response = $controller->show(1);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for update', function (): void {
        [$controller] = makeGroupPreferencesController();
        $response = $controller->update(1, jsonRequest('PUT', '/api/v1/groups/1/preferences', [
            'preferred_llm_config_id' => null,
        ]));
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 404 for a non-existent group on show', function (): void {
        [$controller, $auth] = makeGroupPreferencesController();
        $uid = bootAuth($auth, 'gp1a@example.com', GP_TEST_PASSWORD);
        simulateLoggedInSession($uid, 'gp1a@example.com');
        $response = $controller->show(999_999);
        expect($response->getStatusCode())->toBe(404);
    });

    it('returns 200 with empty defaults when the group exists but has no row yet', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1b-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup');
        simulateLoggedInSession($ownerId, 'gp1b-owner@example.com');

        $response = $controller->show($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['preference']['principal_id'])->toBe((int) $principalService->principalForGroup($group->id)->id);
        expect($body['data']['preference']['preferred_llm_config_id'])->toBeNull();
    });

    it('returns 200 with the existing row when one is present', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1c-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup2');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;

        Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
            'principal_id'           => $principalId,
            'preferred_llm_config_id' => null,
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        simulateLoggedInSession($ownerId, 'gp1c-owner@example.com');
        $response = $controller->show($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['preference']['principal_id'])->toBe($principalId);
        expect($body['data']['preference'])->toHaveKey('updated_at');
    });

    it('creates the row when none exists on PUT (200)', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1d-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup3');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;

        simulateLoggedInSession($ownerId, 'gp1d-owner@example.com');
        $response = $controller->update($group->id, jsonRequest('PUT', '/api/v1/groups/' . $group->id . '/preferences', [
            'preferred_llm_config_id' => null,
        ]));
        expect($response->getStatusCode())->toBe(200);

        $count = (int) Illuminate\Database\Capsule\Manager::table('principal_preferences')
            ->where('principal_id', $principalId)
            ->count();
        expect($count)->toBe(1);
    });

    it('updates the existing row on PUT (200)', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1e-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup4');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;

        Illuminate\Database\Capsule\Manager::table('principal_preferences')->insert([
            'principal_id'           => $principalId,
            'preferred_llm_config_id' => null,
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        simulateLoggedInSession($ownerId, 'gp1e-owner@example.com');
        $response = $controller->update($group->id, jsonRequest('PUT', '/api/v1/groups/' . $group->id . '/preferences', [
            'preferred_llm_config_id' => null,
        ]));
        expect($response->getStatusCode())->toBe(200);

        $count = (int) Illuminate\Database\Capsule\Manager::table('principal_preferences')
            ->where('principal_id', $principalId)
            ->count();
        expect($count)->toBe(1);
    });

    it('returns 403 when caller is member-only', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1f-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup5');

        $memberId = bootAuth($auth, 'gp1f-member@example.com', GP_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $groupService->addMember((int) $group->id, $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);

        simulateLoggedInSession($memberId, 'gp1f-member@example.com');
        $response = $controller->update($group->id, jsonRequest('PUT', '/api/v1/groups/' . $group->id . '/preferences', [
            'preferred_llm_config_id' => null,
        ]));
        expect($response->getStatusCode())->toBe(403);
    });

    it('returns 200 for member-only on GET (members can read)', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1g-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup6');

        $memberId = bootAuth($auth, 'gp1g-member@example.com', GP_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $groupService->addMember((int) $group->id, $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);

        simulateLoggedInSession($memberId, 'gp1g-member@example.com');
        $response = $controller->show($group->id);
        expect($response->getStatusCode())->toBe(200);
    });

    it('returns 404 when caller is not a group member', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1h-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup7');

        $strangerId = bootAuth($auth, 'gp1h-stranger@example.com', GP_TEST_PASSWORD);
        simulateLoggedInSession($strangerId, 'gp1h-stranger@example.com');
        $response = $controller->show($group->id);
        expect($response->getStatusCode())->toBe(404);
    });

    it('returns 422 when preferred_llm_config_id is missing', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1i-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup8');

        simulateLoggedInSession($ownerId, 'gp1i-owner@example.com');
        $response = $controller->update($group->id, jsonRequest('PUT', '/api/v1/groups/' . $group->id . '/preferences', []));
        expect($response->getStatusCode())->toBe(422);
    });

    it('returns 400 on malformed JSON', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1j-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup9');

        simulateLoggedInSession($ownerId, 'gp1j-owner@example.com');
        $broken = Symfony\Component\HttpFoundation\Request::create(
            '/api/v1/groups/' . $group->id . '/preferences',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{ bad json',
        );
        $response = $controller->update($group->id, $broken);
        expect($response->getStatusCode())->toBe(400);
    });

    it('returns 200 on PUT for an admin caller who is not a member', function (): void {
        [$controller, $auth, $principalService] = makeGroupPreferencesController();
        $ownerId = bootAuth($auth, 'gp1k-owner@example.com', GP_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'PrefGroup10');

        $adminId = bootAuth($auth, 'gp1k-admin@example.com', GP_TEST_PASSWORD);
        makeAdmin($auth, $adminId);
        simulateLoggedInSession($adminId, 'gp1k-admin@example.com');

        $response = $controller->update($group->id, jsonRequest('PUT', '/api/v1/groups/' . $group->id . '/preferences', [
            'preferred_llm_config_id' => null,
        ]));
        expect($response->getStatusCode())->toBe(200);
    });
});
