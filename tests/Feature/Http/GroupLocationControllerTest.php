<?php

declare(strict_types=1);

use Spora\Core\RouteDefinitions;
use Spora\Http\GroupLocationController;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Models\PrincipalLocation;
use Spora\OpenApi\RouteSpecCollector;
use Spora\Services\GroupService;
use Spora\Services\PrincipalLocationService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('GL_TEST_PASSWORD') || define('GL_TEST_PASSWORD', 'Password1!');

function makeGroupLocationController(): array
{
    $auth = bootAuthLayer();
    $principalService = new PrincipalService(new PrincipalResolver());
    $controller = new GroupLocationController($auth, $principalService, new PrincipalLocationService());

    return [$controller, $auth, $principalService];
}

describe('GroupLocationController', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('returns 401 when no user is logged in for index', function (): void {
        [$controller] = makeGroupLocationController();
        expect($controller->index(1)->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for store', function (): void {
        [$controller] = makeGroupLocationController();
        $response = $controller->store(1, jsonRequest('POST', '/api/v1/groups/1/locations', ['name' => 'A', 'address' => 'B']));
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for update', function (): void {
        [$controller] = makeGroupLocationController();
        $response = $controller->update(1, 1, jsonRequest('PATCH', '/api/v1/groups/1/locations/1', ['name' => 'A']));
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for destroy', function (): void {
        [$controller] = makeGroupLocationController();
        expect($controller->destroy(1, 1)->getStatusCode())->toBe(401);
    });

    it('returns 404 for a group the caller is not a member of', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-not-member-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Hidden');
        $strangerId = bootAuth($auth, 'gl-stranger@example.com', GL_TEST_PASSWORD);
        simulateLoggedInSession($strangerId, 'gl-stranger@example.com');

        $response = $controller->index($group->id);

        expect($response->getStatusCode())->toBe(404);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('GROUP_NOT_FOUND');
    });

    it('returns 404 for a group that does not exist', function (): void {
        [$controller, $auth] = makeGroupLocationController();
        $userId = bootAuth($auth, 'gl-missing@example.com', GL_TEST_PASSWORD);
        simulateLoggedInSession($userId, 'gl-missing@example.com');

        expect($controller->index(999_999)->getStatusCode())->toBe(404);
    });

    it('lists the locations of the group for a member', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-list-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        PrincipalLocation::create([
            'principal_id' => $principalId,
            'name'         => 'HQ',
            'address'      => 'Musterstraße 1',
            'is_default'   => true,
        ]);
        simulateLoggedInSession($ownerId, 'gl-list-owner@example.com');

        $response = $controller->index($group->id);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['locations'])->toHaveCount(1);
        expect($body['data']['locations'][0]['name'])->toBe('HQ');
        expect($body['data']['locations'][0]['is_default'])->toBeTrue();
    });

    it('creates a location and returns it', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-create-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        simulateLoggedInSession($ownerId, 'gl-create-owner@example.com');

        $response = $controller->store($group->id, jsonRequest('POST', '/api/v1/groups/' . $group->id . '/locations', [
            'name'       => 'HQ',
            'address'    => 'Musterstraße 1',
            'is_default' => true,
        ]));

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['location']['name'])->toBe('HQ');
        expect($body['data']['location']['is_default'])->toBeTrue();
    });

    it('rejects an empty name on create', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-empty-name-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        simulateLoggedInSession($ownerId, 'gl-empty-name-owner@example.com');

        $response = $controller->store($group->id, jsonRequest('POST', '/api/v1/groups/' . $group->id . '/locations', [
            'name'    => '   ',
            'address' => 'Musterstraße 1',
        ]));

        expect($response->getStatusCode())->toBe(422);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
    });

    it('rejects an empty address on create', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-empty-address-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        simulateLoggedInSession($ownerId, 'gl-empty-address-owner@example.com');

        $response = $controller->store($group->id, jsonRequest('POST', '/api/v1/groups/' . $group->id . '/locations', [
            'name'    => 'HQ',
            'address' => '',
        ]));

        expect($response->getStatusCode())->toBe(422);
    });

    it('refuses a member whose role is below admin', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-role-owner@example.com', GL_TEST_PASSWORD);
        $groupService = new GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'Locations');
        $memberId = bootAuth($auth, 'gl-role-member@example.com', GL_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, $memberId, 'member', $ownerId);
        simulateLoggedInSession($memberId, 'gl-role-member@example.com');

        $response = $controller->store($group->id, jsonRequest('POST', '/api/v1/groups/' . $group->id . '/locations', [
            'name'    => 'HQ',
            'address' => 'Musterstraße 1',
        ]));

        expect($response->getStatusCode())->toBe(403);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('FORBIDDEN');
    });

    it('updates a location of the group', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-update-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $location = PrincipalLocation::create([
            'principal_id' => $principalId,
            'name'         => 'Old',
            'address'      => 'Old address',
        ]);
        simulateLoggedInSession($ownerId, 'gl-update-owner@example.com');

        $response = $controller->update(
            $group->id,
            (int) $location->id,
            jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/locations/' . $location->id, [
                'name'    => 'New',
                'address' => 'New address',
            ]),
        );

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['location']['name'])->toBe('New');
        expect($body['data']['location']['address'])->toBe('New address');
    });

    it('returns 404 for a location that belongs to another principal', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-foreign-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        $otherId = bootAuth($auth, 'gl-foreign-other@example.com', GL_TEST_PASSWORD);
        $otherPrincipalId = $this->createUserPrincipal($otherId);
        $foreign = PrincipalLocation::create([
            'principal_id' => $otherPrincipalId,
            'name'         => 'Theirs',
            'address'      => 'Elsewhere 2',
        ]);
        simulateLoggedInSession($ownerId, 'gl-foreign-owner@example.com');

        $response = $controller->update(
            $group->id,
            (int) $foreign->id,
            jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/locations/' . $foreign->id, ['name' => 'Hacked']),
        );

        expect($response->getStatusCode())->toBe(404);
        expect(json_decode($response->getContent(), true)['error']['code'])->toBe('NOT_FOUND');
        expect(PrincipalLocation::find($foreign->id)->name)->toBe('Theirs');
    });

    it('rejects an empty name on update', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-update-empty-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $location = PrincipalLocation::create([
            'principal_id' => $principalId,
            'name'         => 'Original',
            'address'      => 'Musterstraße 1',
        ]);
        simulateLoggedInSession($ownerId, 'gl-update-empty-owner@example.com');

        $response = $controller->update(
            $group->id,
            (int) $location->id,
            jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/locations/' . $location->id, ['name' => '']),
        );

        expect($response->getStatusCode())->toBe(422);
        expect(PrincipalLocation::find($location->id)->name)->toBe('Original');
    });

    it('reads a null field as "not mentioned" rather than "set to nothing"', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-update-null-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $location = PrincipalLocation::create([
            'principal_id' => $principalId,
            'name'         => 'Original',
            'address'      => 'Musterstraße 1',
        ]);
        simulateLoggedInSession($ownerId, 'gl-update-null-owner@example.com');

        $response = $controller->update(
            $group->id,
            (int) $location->id,
            jsonRequest('PATCH', '/api/v1/groups/' . $group->id . '/locations/' . $location->id, ['name' => null]),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(PrincipalLocation::find($location->id)->name)->toBe('Original');
    });

    it('refuses a name that is not a string', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-nonstring-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        simulateLoggedInSession($ownerId, 'gl-nonstring-owner@example.com');

        $response = $controller->store(
            $group->id,
            jsonRequest('POST', '/api/v1/groups/' . $group->id . '/locations', ['name' => ['x'], 'address' => 'Musterstraße 1']),
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('refuses a name longer than the column', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-longname-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        simulateLoggedInSession($ownerId, 'gl-longname-owner@example.com');

        $response = $controller->store(
            $group->id,
            jsonRequest('POST', '/api/v1/groups/' . $group->id . '/locations', [
                'name'    => str_repeat('x', 101),
                'address' => 'Musterstraße 1',
            ]),
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('deletes a location of the group', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-delete-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $location = PrincipalLocation::create([
            'principal_id' => $principalId,
            'name'         => 'To Delete',
            'address'      => 'Musterstraße 1',
        ]);
        simulateLoggedInSession($ownerId, 'gl-delete-owner@example.com');

        $response = $controller->destroy($group->id, (int) $location->id);

        expect($response->getStatusCode())->toBe(200);
        expect(json_decode($response->getContent(), true)['data']['deleted'])->toBeTrue();
        expect(PrincipalLocation::find($location->id))->toBeNull();
    });

    it('returns 404 when deleting a location that belongs to another principal', function (): void {
        [$controller, $auth, $principalService] = makeGroupLocationController();
        $ownerId = bootAuth($auth, 'gl-delete-foreign-owner@example.com', GL_TEST_PASSWORD);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'Locations');
        $otherId = bootAuth($auth, 'gl-delete-foreign-other@example.com', GL_TEST_PASSWORD);
        $otherPrincipalId = $this->createUserPrincipal($otherId);
        $foreign = PrincipalLocation::create([
            'principal_id' => $otherPrincipalId,
            'name'         => 'Theirs',
            'address'      => 'Elsewhere 2',
        ]);
        simulateLoggedInSession($ownerId, 'gl-delete-foreign-owner@example.com');

        $response = $controller->destroy($group->id, (int) $foreign->id);

        expect($response->getStatusCode())->toBe(404);
        expect(PrincipalLocation::find($foreign->id))->not->toBeNull();
    });
});

describe('the group location routes', function (): void {
    it('maps the four endpoints onto the controller', function (): void {
        $collector = new RouteSpecCollector();
        RouteDefinitions::register($collector);

        $rows = array_values(array_filter(
            $collector->routes(),
            static fn(array $row): bool => str_contains((string) $row['route'], '/groups/{id}/locations'),
        ));

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[$row['method'] . ' ' . $row['route']] = $row['handler'];
        }

        expect($mapped)->toBe([
            'GET /api/v1/groups/{id}/locations' => [GroupLocationController::class, 'index'],
            'POST /api/v1/groups/{id}/locations' => [GroupLocationController::class, 'store'],
            'PATCH /api/v1/groups/{id}/locations/{locationId}' => [GroupLocationController::class, 'update'],
            'DELETE /api/v1/groups/{id}/locations/{locationId}' => [GroupLocationController::class, 'destroy'],
        ]);
    });

    it('gates every endpoint behind auth and CSRF', function (): void {
        $collector = new RouteSpecCollector();
        RouteDefinitions::register($collector);

        $rows = array_values(array_filter(
            $collector->routes(),
            static fn(array $row): bool => str_contains((string) $row['route'], '/groups/{id}/locations'),
        ));

        expect($rows)->toHaveCount(4);
        foreach ($rows as $row) {
            expect($row['middleware'])->toContain(AuthMiddleware::class);
            expect($row['middleware'])->toContain(CsrfMiddleware::class);
        }
    });
});
