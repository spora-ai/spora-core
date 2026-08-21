<?php

declare(strict_types=1);

use Spora\Http\PrincipalController;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('PRINCCTRL_TEST_PASSWORD') || define('PRINCCTRL_TEST_PASSWORD', 'Password1!');

function makePrincipalControllerHarness(): array
{
    $auth = bootAuthLayer();
    $resolver = new PrincipalResolver();
    $principalService = new PrincipalService($resolver);
    $controller = new PrincipalController($auth, $resolver);

    return [$controller, $auth, $resolver, $principalService];
}

function seedCallerWithUserAndGroup(int $callerId, PrincipalService $ps, GroupService $gs): int
{
    $ps->ensureUserPrincipal($callerId);
    $groupId = (int) $gs->createGroup($callerId, 'CoverageGroup')->id;
    $ps->ensureGroupPrincipal($groupId);
    GroupMembership::where('group_id', $groupId)->where('user_id', $callerId)->delete();
    GroupMembership::create(['group_id' => $groupId, 'user_id' => $callerId, 'role' => GroupMembership::ROLE_OWNER]);
    return $groupId;
}

describe('PrincipalController', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('returns 401 when no user is logged in', function (): void {
        [$controller] = makePrincipalControllerHarness();
        $response = $controller->currentForUser();
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns an empty list when the caller has no user-principal yet', function (): void {
        [$controller, $auth] = makePrincipalControllerHarness();
        $callerId = bootAuth($auth, 'pc-empty@example.com', PRINCCTRL_TEST_PASSWORD);
        simulateLoggedInSession($callerId, 'pc-empty@example.com');

        $response = $controller->currentForUser();
        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['principals'])->toBe([]);
    });

    it('returns the user-principal + every group-principal the caller belongs to', function (): void {
        [$controller, $auth, $resolver, $ps] = makePrincipalControllerHarness();

        $callerId = bootAuth($auth, 'pc-mixed@example.com', PRINCCTRL_TEST_PASSWORD);
        $otherId = bootAuth($auth, 'pc-other@example.com', PRINCCTRL_TEST_PASSWORD);
        simulateLoggedInSession($callerId, 'pc-mixed@example.com');

        $gs = new GroupService($ps);
        $myGroupId = seedCallerWithUserAndGroup($callerId, $ps, $gs);

        $otherGroupId = (int) $gs->createGroup($otherId, 'OtherGroup')->id;
        $ps->ensureGroupPrincipal($otherGroupId);

        $response = $controller->currentForUser();
        $body = json_decode($response->getContent(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['data']['principals'])->not->toBe([]);

        $ids = array_column($body['data']['principals'], 'id');
        $myPrincipal = Principal::where('type', Principal::TYPE_GROUP)->where('group_id', $myGroupId)->first();
        $otherPrincipal = Principal::where('type', Principal::TYPE_GROUP)->where('group_id', $otherGroupId)->first();

        expect($ids)->toContain((int) $myPrincipal->id);
        expect($ids)->not->toContain((int) $otherPrincipal->id);

        $kinds = array_column($body['data']['principals'], 'type');
        expect($kinds)->toContain('user');
        expect($kinds)->toContain('group');
    });

    it('includes both user_id and group_id fields correctly per row', function (): void {
        [$controller, $auth, $resolver, $ps] = makePrincipalControllerHarness();

        $callerId = bootAuth($auth, 'pc-fields@example.com', PRINCCTRL_TEST_PASSWORD);
        simulateLoggedInSession($callerId, 'pc-fields@example.com');

        $gs = new GroupService($ps);
        $groupId = seedCallerWithUserAndGroup($callerId, $ps, $gs);

        $response = $controller->currentForUser();
        $body = json_decode($response->getContent(), true);

        $userRow = collect($body['data']['principals'])->firstWhere('type', 'user');
        $groupRow = collect($body['data']['principals'])->firstWhere('type', 'group');

        expect($userRow['user_id'])->toBe($callerId);
        expect($userRow['group_id'])->toBeNull();
        expect($groupRow['user_id'])->toBeNull();
        expect($groupRow['group_id'])->toBe($groupId);
    });
});
