<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Http\GroupToolsController;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;
use Spora\Tools\TimeTool;

defined('GT_TEST_PASSWORD') || define('GT_TEST_PASSWORD', 'Password1!');

function makeGroupToolsController(): array
{
    $auth = bootAuthLayer();
    $principalService = new PrincipalService(new PrincipalResolver());
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new ToolConfigService($security, new Psr\Log\NullLogger(), [CalculatorTool::class, TimeTool::class]);
    $controller = new GroupToolsController($auth, $principalService, $toolConfig);

    return [$controller, $auth, $principalService, $toolConfig];
}

describe('GroupToolsController', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('returns 401 when no user is logged in for index', function (): void {
        [$controller] = makeGroupToolsController();
        $response = $controller->index(1);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for upsert', function (): void {
        [$controller] = makeGroupToolsController();
        $response = $controller->upsert(1, CalculatorTool::class, jsonRequest('POST', '/x', ['settings' => []]));
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no user is logged in for destroy', function (): void {
        [$controller] = makeGroupToolsController();
        $response = $controller->destroy(1, CalculatorTool::class);
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 200 with empty tools list when no rows exist', function (): void {
        [$controller, $auth, $principalService] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1a-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup1');
        simulateLoggedInSession($ownerId, 'gt1a-owner@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['tools'])->toBe([]);
    });

    it('returns 200 listing existing rows', function (): void {
        [$controller, $auth, $principalService, $toolConfig] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1b-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup2');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $toolConfig->putPrincipalSettings(CalculatorTool::class, $principalId, ['precision' => 4]);
        simulateLoggedInSession($ownerId, 'gt1b-owner@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['tools'])->toHaveCount(1);
        expect($body['data']['tools'][0]['tool_class'])->toBe(CalculatorTool::class);
        expect($body['data']['tools'][0]['settings'])->toHaveKey('precision');
    });

    it('creates a new tool user setting on upsert (200)', function (): void {
        [$controller, $auth, $principalService, $toolConfig] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1c-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup3');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        simulateLoggedInSession($ownerId, 'gt1c-owner@example.com');

        $response = $controller->upsert($group->id, CalculatorTool::class, jsonRequest('POST', '/x', [
            'settings' => ['precision' => 8],
        ]));
        expect($response->getStatusCode())->toBe(200);

        $count = (int) Illuminate\Database\Capsule\Manager::table('tool_user_settings')
            ->where('principal_id', $principalId)
            ->where('tool_class', CalculatorTool::class)
            ->count();
        expect($count)->toBe(1);
        $stored = $toolConfig->getPrincipalSettings(CalculatorTool::class, $principalId);
        expect($stored['precision'] ?? null)->toBe(8);
    });

    it('updates an existing tool user setting on upsert (200)', function (): void {
        [$controller, $auth, $principalService, $toolConfig] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1d-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup4');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $toolConfig->putPrincipalSettings(CalculatorTool::class, $principalId, ['precision' => 2]);
        simulateLoggedInSession($ownerId, 'gt1d-owner@example.com');

        $response = $controller->upsert($group->id, CalculatorTool::class, jsonRequest('POST', '/x', [
            'settings' => ['precision' => 16],
        ]));
        expect($response->getStatusCode())->toBe(200);

        $count = (int) Illuminate\Database\Capsule\Manager::table('tool_user_settings')
            ->where('principal_id', $principalId)
            ->where('tool_class', CalculatorTool::class)
            ->count();
        expect($count)->toBe(1);
        $stored = $toolConfig->getPrincipalSettings(CalculatorTool::class, $principalId);
        expect($stored['precision'] ?? null)->toBe(16);
    });

    it('deletes the row on destroy (200)', function (): void {
        [$controller, $auth, $principalService, $toolConfig] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1e-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup5');
        $principalId = (int) $principalService->principalForGroup($group->id)->id;
        $toolConfig->putPrincipalSettings(CalculatorTool::class, $principalId, ['precision' => 2]);
        simulateLoggedInSession($ownerId, 'gt1e-owner@example.com');

        $response = $controller->destroy($group->id, CalculatorTool::class);
        expect($response->getStatusCode())->toBe(200);

        $count = (int) Illuminate\Database\Capsule\Manager::table('tool_user_settings')
            ->where('principal_id', $principalId)
            ->where('tool_class', CalculatorTool::class)
            ->count();
        expect($count)->toBe(0);
    });

    it('returns 403 when caller is member-only on upsert', function (): void {
        [$controller, $auth, $principalService] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1f-owner@example.com', GT_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'ToolsGroup6');
        $memberId = bootAuth($auth, 'gt1f-member@example.com', GT_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
        simulateLoggedInSession($memberId, 'gt1f-member@example.com');

        $response = $controller->upsert($group->id, CalculatorTool::class, jsonRequest('POST', '/x', [
            'settings' => ['precision' => 4],
        ]));
        expect($response->getStatusCode())->toBe(403);
    });

    it('returns 200 for member-only on index', function (): void {
        [$controller, $auth, $principalService] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1g-owner@example.com', GT_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'ToolsGroup7');
        $memberId = bootAuth($auth, 'gt1g-member@example.com', GT_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, $memberId, Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
        simulateLoggedInSession($memberId, 'gt1g-member@example.com');

        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(200);
    });

    it('returns 404 when group does not exist', function (): void {
        [$controller, $auth] = makeGroupToolsController();
        $uid = bootAuth($auth, 'gt1h@example.com', GT_TEST_PASSWORD);
        simulateLoggedInSession($uid, 'gt1h@example.com');
        $response = $controller->index(999_999);
        expect($response->getStatusCode())->toBe(404);
    });

    it('returns 404 when caller is not a group member', function (): void {
        [$controller, $auth, $principalService] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1i-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup8');
        $strangerId = bootAuth($auth, 'gt1i-stranger@example.com', GT_TEST_PASSWORD);
        simulateLoggedInSession($strangerId, 'gt1i-stranger@example.com');
        $response = $controller->index($group->id);
        expect($response->getStatusCode())->toBe(404);
    });

    it('returns 400 on malformed JSON upsert', function (): void {
        [$controller, $auth, $principalService] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1j-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup9');
        simulateLoggedInSession($ownerId, 'gt1j-owner@example.com');

        $broken = Symfony\Component\HttpFoundation\Request::create(
            '/x',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{ bad',
        );
        $response = $controller->upsert($group->id, CalculatorTool::class, $broken);
        expect($response->getStatusCode())->toBe(400);
    });

    it('returns 422 when settings is not an object', function (): void {
        [$controller, $auth, $principalService] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1k-owner@example.com', GT_TEST_PASSWORD);
        $group = (new Spora\Services\GroupService($principalService))->createGroup($ownerId, 'ToolsGroup10');
        simulateLoggedInSession($ownerId, 'gt1k-owner@example.com');

        $response = $controller->upsert($group->id, CalculatorTool::class, jsonRequest('POST', '/x', [
            'settings' => 'not-an-object',
        ]));
        expect($response->getStatusCode())->toBe(422);
    });

    it('writes under the GROUP principal, not the caller user-principal', function (): void {
        [$controller, $auth, $principalService] = makeGroupToolsController();
        $ownerId = bootAuth($auth, 'gt1l-owner@example.com', GT_TEST_PASSWORD);
        $groupService = new Spora\Services\GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'ToolsGroup11');
        $groupPrincipalId = (int) $principalService->principalForGroup($group->id)->id;
        simulateLoggedInSession($ownerId, 'gt1l-owner@example.com');

        $response = $controller->upsert($group->id, CalculatorTool::class, jsonRequest('POST', '/x', [
            'settings' => ['precision' => 9],
        ]));
        expect($response->getStatusCode())->toBe(200);

        // The row's principal_id must be the group's, never the caller's user-principal.
        $row = Illuminate\Database\Capsule\Manager::table('tool_user_settings')
            ->where('tool_class', CalculatorTool::class)
            ->first();
        expect((int) $row->principal_id)->toBe($groupPrincipalId);
        expect((int) $row->principal_id)->not->toBe((int) $principalService->ensureUserPrincipal($ownerId)->id);
    });
});
