<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\Exceptions\TransferTargetNotFoundException;
use Spora\Services\Exceptions\UnauthorizedTransferException;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('PRINC_TEST_PASSWORD') || define('PRINC_TEST_PASSWORD', 'Password1!');

function makePrincipalService(): array
{
    $auth = bootAuthLayer();
    $resolver = new PrincipalResolver();
    $service = new PrincipalService($resolver);

    return [$service, $resolver, $auth];
}

function seedUserAgent(int $principalId, string $name): int
{
    return (int) Capsule::table('agents')->insertGetId([
        'principal_id'           => $principalId,
        'name'                   => $name,
        'description'            => null,
        'llm_driver_config_id'   => null,
        'max_steps'              => 10,
        'is_active'              => 1,
        'created_at'             => date('Y-m-d H:i:s'),
        'updated_at'             => date('Y-m-d H:i:s'),
    ]);
}

describe('PrincipalService::ensureUserPrincipal', function (): void {
    it('is idempotent for the same user', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-idem@example.com', PRINC_TEST_PASSWORD);

        $a = $service->ensureUserPrincipal($userId);
        $b = $service->ensureUserPrincipal($userId);
        expect($a->id)->toBe($b->id);
    });

    it('refuses to materialise a user-principal for an unknown user_id', function (): void {
        [$service] = makePrincipalService();
        expect(fn() => $service->ensureUserPrincipal(987_654))
            ->toThrow(Spora\Services\Exceptions\PrincipalMaterialisationException::class);
    });
});

describe('PrincipalService::ensureGroupPrincipal', function (): void {
    it('creates the principal for a fresh group', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-gp-owner@example.com', PRINC_TEST_PASSWORD);

        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PS GP')->id;

        $principal = $service->ensureGroupPrincipal($groupId);
        expect((string) $principal->type)->toBe('group')
            ->and((int) $principal->group_id)->toBe($groupId);
    });

    it('is idempotent for an already-materialised group-principal', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-gp-idem@example.com', PRINC_TEST_PASSWORD);

        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PS GP Idem')->id;

        $a = $service->ensureGroupPrincipal($groupId);
        $b = $service->ensureGroupPrincipal($groupId);
        expect($a->id)->toBe($b->id);
    });
});

describe('PrincipalService::principalForGroup', function (): void {
    it('returns null for a group without a principal', function (): void {
        [$service] = makePrincipalService();
        expect($service->principalForGroup(999_999))->toBeNull();
    });

    it('returns the principal when one exists', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-pfg@example.com', PRINC_TEST_PASSWORD);

        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PS PFG')->id;

        $service->ensureGroupPrincipal($groupId);
        $principal = $service->principalForGroup($groupId);
        expect($principal)->not->toBeNull()
            ->and((int) $principal->group_id)->toBe($groupId);
    });
});

describe('PrincipalService::callerControlsPrincipal', function (): void {
    it('returns true when the caller owns the principal', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-ctrl@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($userId);

        expect($service->callerControlsPrincipal($userId, (int) $principal->id))->toBeTrue();
    });

    it('returns false for a different user', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-ctrl-o@example.com', PRINC_TEST_PASSWORD);
        $otherId = bootAuth($auth, 'ps-ctrl-x@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($ownerId);

        expect($service->callerControlsPrincipal($otherId, (int) $principal->id))->toBeFalse();
    });

    it('returns true when the caller is owner of the underlying group', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-ctrl-grp-owner@example.com', PRINC_TEST_PASSWORD);
        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PS Ctrl Grp Owner')->id;
        $principal = $service->ensureGroupPrincipal($groupId);

        expect($service->callerControlsPrincipal($ownerId, (int) $principal->id))->toBeTrue();
    });

    it('returns true when the caller is admin of the underlying group', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-ctrl-grp-adm-owner@example.com', PRINC_TEST_PASSWORD);
        $adminId = bootAuth($auth, 'ps-ctrl-grp-adm-admin@example.com', PRINC_TEST_PASSWORD);
        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PS Ctrl Grp Admin')->id;
        $principal = $service->ensureGroupPrincipal($groupId);
        $groupService->addMember($groupId, $adminId, GroupMembership::ROLE_ADMIN, $ownerId);

        expect($service->callerControlsPrincipal($adminId, (int) $principal->id))->toBeTrue();
    });

    it('returns false for a member without admin/owner role', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-ctrl-grp-mem-owner@example.com', PRINC_TEST_PASSWORD);
        $memberId = bootAuth($auth, 'ps-ctrl-grp-mem@example.com', PRINC_TEST_PASSWORD);
        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PS Ctrl Grp Member')->id;
        $principal = $service->ensureGroupPrincipal($groupId);
        $groupService->addMember($groupId, $memberId, GroupMembership::ROLE_MEMBER, $ownerId);

        expect($service->callerControlsPrincipal($memberId, (int) $principal->id))->toBeFalse();
    });

    it('returns false for an unknown principal', function (): void {
        [$service] = makePrincipalService();
        expect($service->callerControlsPrincipal(1, 999_999))->toBeFalse();
    });
});

describe('PrincipalService::visiblePrincipalIdsFor', function (): void {
    it('delegates to PrincipalResolver::visiblePrincipalIds', function (): void {
        [$service, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-vp@example.com', PRINC_TEST_PASSWORD);
        $service->ensureUserPrincipal($userId);

        expect($service->visiblePrincipalIdsFor($userId))->toBe($resolver->visiblePrincipalIds($userId));
    });
});

describe('PrincipalService::dependentAgentIds', function (): void {
    it('returns agent ids that point at the supplied principals', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-dep@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($userId);

        $aid = seedUserAgent((int) $principal->id, 'A');
        $ids = $service->dependentAgentIds([(int) $principal->id]);
        expect($ids)->toContain($aid);
    });

    it('returns an empty array when no principals are supplied', function (): void {
        [$service] = makePrincipalService();
        expect($service->dependentAgentIds([]))->toBe([]);
    });

    it('returns an empty array when no agents point at the supplied principals', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-dep-empty@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($userId);

        expect($service->dependentAgentIds([(int) $principal->id]))->toBe([]);
    });
});

describe('PrincipalService::transferAgent', function (): void {
    it('rejects calls by callers who do not admin the source principal', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'ps-tx-o@example.com', PRINC_TEST_PASSWORD);
        $otherId = bootAuth($auth, 'ps-tx-x@example.com', PRINC_TEST_PASSWORD);
        $targetId = bootAuth($auth, 'ps-tx-t@example.com', PRINC_TEST_PASSWORD);

        $principal = $service->ensureUserPrincipal($ownerId);
        $targetPrincipal = $service->ensureUserPrincipal($targetId);
        $aid = seedUserAgent((int) $principal->id, 'Tra');

        expect(fn() => $service->transferAgent($aid, (int) $targetPrincipal->id, $otherId))
            ->toThrow(UnauthorizedTransferException::class);
    });

    it('rejects when the agent does not exist', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-tx-missing@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($userId);

        expect(fn() => $service->transferAgent(999_999, (int) $principal->id, $userId))
            ->toThrow(TransferTargetNotFoundException::class);
    });

    it('rejects when the target principal does not exist', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-tx-bad-target@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($userId);
        $aid = seedUserAgent((int) $principal->id, 'BT');

        expect(fn() => $service->transferAgent($aid, 999_999, $userId))
            ->toThrow(TransferTargetNotFoundException::class);
    });

    it('rejects when caller admins the source but not the target', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $callerId = bootAuth($auth, 'ps-tx-src@example.com', PRINC_TEST_PASSWORD);
        $otherId = bootAuth($auth, 'ps-tx-tgt@example.com', PRINC_TEST_PASSWORD);
        $sourcePrincipal = $service->ensureUserPrincipal($callerId);
        $targetPrincipal = $service->ensureUserPrincipal($otherId);
        $aid = seedUserAgent((int) $sourcePrincipal->id, 'Cros');

        expect(fn() => $service->transferAgent($aid, (int) $targetPrincipal->id, $callerId))
            ->toThrow(UnauthorizedTransferException::class);
    });

    it('transfers ownership when caller controls both source and target', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-tx-happy@example.com', PRINC_TEST_PASSWORD);
        $sourcePrincipal = $service->ensureUserPrincipal($userId);
        $targetPrincipal = $service->ensureUserPrincipal($userId);
        $aid = seedUserAgent((int) $sourcePrincipal->id, 'Happy');

        $agent = $service->transferAgent($aid, (int) $targetPrincipal->id, $userId);
        expect((int) $agent->principal_id)->toBe((int) $targetPrincipal->id);
    });

    it('is a no-op when source and target are identical', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-tx-noop@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($userId);
        $aid = seedUserAgent((int) $principal->id, 'Noop');

        $agent = $service->transferAgent($aid, (int) $principal->id, $userId);
        expect((int) $agent->principal_id)->toBe((int) $principal->id);
    });
});

describe('PrincipalResolver::ownerUserId', function (): void {
    it('returns the user_id for a user-principal', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'pr-owner-u@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($userId);

        expect($resolver->ownerUserId((int) $principal->id))->toBe($userId);
    });

    it('returns the first owner for a group-principal', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'pr-owner-g@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PR Owner Grp')->id;
        $principal = $service->ensureGroupPrincipal($groupId);

        expect($resolver->ownerUserId((int) $principal->id))->toBe($ownerId);
    });

    it('returns null when the principal does not exist', function (): void {
        [, $resolver] = makePrincipalService();
        expect($resolver->ownerUserId(999_999))->toBeNull();
    });

    it('returns null when the group has no owner row', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'pr-owner-empty@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PR Owner Empty')->id;
        $principal = $service->ensureGroupPrincipal($groupId);
        Capsule::table('group_memberships')->where('group_id', $groupId)->delete();

        expect($resolver->ownerUserId((int) $principal->id))->toBeNull();
    });
});

describe('PrincipalResolver::runnerUserId', function (): void {
    it('returns the latest task runner for the agent', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'pr-runner@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($userId);
        $aid = seedUserAgent((int) $principal->id, 'Run');
        Capsule::table('tasks')->insert([
            'agent_id'     => $aid,
            'principal_id' => (int) $principal->id,
            'trigger_user_id' => $userId,
            'status'       => 'COMPLETED',
            'user_prompt'  => 'hi',
            'step_count'   => 1,
            'max_steps'    => 10,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        expect($resolver->runnerUserId($aid))->toBe($userId);
    });

    it('falls back to the agent owner when the agent has no tasks', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'pr-runner-cold@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($userId);
        $aid = seedUserAgent((int) $principal->id, 'Cold');

        expect($resolver->runnerUserId($aid))->toBe($userId);
    });

    it('returns null when the agent does not exist', function (): void {
        [, $resolver] = makePrincipalService();
        expect($resolver->runnerUserId(999_999))->toBeNull();
    });
});

describe('PrincipalResolver::isVisibleTo', function (): void {
    it('returns true for the owner', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'pr-vis-yes@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($userId);
        $aid = seedUserAgent((int) $principal->id, 'Vis Yes');

        expect($resolver->isVisibleTo($aid, $userId))->toBeTrue();
    });

    it('returns false for a stranger', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'pr-vis-no-owner@example.com', PRINC_TEST_PASSWORD);
        $otherId = bootAuth($auth, 'pr-vis-no-other@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($ownerId);
        $aid = seedUserAgent((int) $principal->id, 'Vis No');

        expect($resolver->isVisibleTo($aid, $otherId))->toBeFalse();
    });

    it('returns false when the caller has no principals at all', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'pr-vis-no-p@example.com', PRINC_TEST_PASSWORD);
        $otherId = bootAuth($auth, 'pr-vis-no-p-other@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($ownerId);
        $aid = seedUserAgent((int) $principal->id, 'Vis No P');

        expect($resolver->isVisibleTo($aid, $otherId))->toBeFalse();
    });
});

describe('PrincipalResolver::resolveForToolExecute', function (): void {
    it('returns the default context for an unknown agent', function (): void {
        [, $resolver] = makePrincipalService();
        $ctx = $resolver->resolveForToolExecute(999_999);
        expect($ctx->principalId)->toBe(0)
            ->and($ctx->ownerUserId)->toBeNull()
            ->and($ctx->runnerUserId)->toBeNull();
    });

    it('returns the principal context for a known agent', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'pr-ctx@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($userId);
        $aid = seedUserAgent((int) $principal->id, 'Ctx');

        $ctx = $resolver->resolveForToolExecute($aid);
        expect((int) $ctx->principalId)->toBe((int) $principal->id)
            ->and($ctx->ownerUserId)->toBe($userId)
            ->and($ctx->runnerUserId)->toBe($userId);
    });
});

describe('PrincipalResolver::isPrincipalOwner', function (): void {
    it('returns true for the user-principal owner', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'pr-ipo-u@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $principal = $service->ensureUserPrincipal($userId);

        expect($resolver->isPrincipalOwner($userId, (int) $principal->id))->toBeTrue();
    });

    it('returns true for the group owner', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'pr-ipo-g-owner@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PR IPO Grp')->id;
        $principal = $service->ensureGroupPrincipal($groupId);

        expect($resolver->isPrincipalOwner($ownerId, (int) $principal->id))->toBeTrue();
    });

    it('returns false for a non-owner group member', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'pr-ipo-g-mem-owner@example.com', PRINC_TEST_PASSWORD);
        $memberId = bootAuth($auth, 'pr-ipo-g-mem@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $groupService = new GroupService($service);
        $groupId = (int) $groupService->createGroup($ownerId, 'PR IPO Grp Mem')->id;
        $principal = $service->ensureGroupPrincipal($groupId);
        $groupService->addMember($groupId, $memberId, GroupMembership::ROLE_MEMBER, $ownerId);

        expect($resolver->isPrincipalOwner($memberId, (int) $principal->id))->toBeFalse();
    });

    it('returns false for an unknown principal', function (): void {
        [, $resolver] = makePrincipalService();
        expect($resolver->isPrincipalOwner(1, 999_999))->toBeFalse();
    });
});

describe('PrincipalResolver::visiblePrincipalIds', function (): void {
    it('returns at least the user-principal', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-vis@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $service->ensureUserPrincipal($userId);

        $ids = $resolver->visiblePrincipalIds($userId);
        expect($ids)->not->toBeEmpty();
    });

    it('returns [] when the user has no user-principal', function (): void {
        [, $resolver] = makePrincipalService();
        expect($resolver->visiblePrincipalIds(987_654))->toBe([]);
    });

    it('includes group-principals for groups the user belongs to', function (): void {
        [, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $callerId = bootAuth($auth, 'ps-vis-grp-owner@example.com', PRINC_TEST_PASSWORD);
        $otherId = bootAuth($auth, 'ps-vis-grp-other@example.com', PRINC_TEST_PASSWORD);
        $service = new PrincipalService($resolver);
        $service->ensureUserPrincipal($callerId);
        $groupService = new GroupService($service);
        $myGroupId = (int) $groupService->createGroup($callerId, 'VisMine')->id;
        $otherGroupId = (int) $groupService->createGroup($otherId, 'VisOther')->id;
        $myPrincipal = $service->ensureGroupPrincipal($myGroupId);
        $service->ensureGroupPrincipal($otherGroupId);

        $ids = $resolver->visiblePrincipalIds($callerId);
        expect($ids)->toContain((int) $myPrincipal->id);
        expect(array_filter(
            Principal::where('type', Principal::TYPE_GROUP)->whereIn('id', $ids)->get()->all(),
            fn(Principal $p): bool => (int) $p->group_id === $otherGroupId,
        ))->toBeEmpty();
    });
});
