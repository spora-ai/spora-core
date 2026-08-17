<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\Exceptions\UnauthorizedTransferException;
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

describe('PrincipalService::ensureUserPrincipal', function (): void {
    it('is idempotent for the same user', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-idem@example.com', PRINC_TEST_PASSWORD);

        $a = $service->ensureUserPrincipal($userId);
        $b = $service->ensureUserPrincipal($userId);
        expect($a->id)->toBe($b->id);
    });
});

describe('PrincipalService::principalForGroup', function (): void {
    it('returns null for a group without a principal', function (): void {
        [$service] = makePrincipalService();
        expect($service->principalForGroup(999_999))->toBeNull();
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
});

describe('PrincipalService::dependentAgentIds', function (): void {
    it('returns agent ids that point at the supplied principals', function (): void {
        [$service] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-dep@example.com', PRINC_TEST_PASSWORD);
        $principal = $service->ensureUserPrincipal($userId);

        $aid = (int) Capsule::table('agents')->insertGetId([
            'principal_id'     => $principal->id,
            'name'             => 'A',
            'description'      => null,
            'llm_driver_config_id' => null,
            'max_steps'        => 10,
            'is_active'        => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $ids = $service->dependentAgentIds([(int) $principal->id]);
        expect($ids)->toContain($aid);
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

        $aid = (int) Capsule::table('agents')->insertGetId([
            'principal_id'     => $principal->id,
            'name'             => 'Tra',
            'description'      => null,
            'llm_driver_config_id' => null,
            'max_steps'        => 10,
            'is_active'        => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        expect(fn() => $service->transferAgent($aid, (int) $targetPrincipal->id, $otherId))
            ->toThrow(UnauthorizedTransferException::class);
    });
});

describe('PrincipalResolver::visiblePrincipalIds', function (): void {
    it('returns at least the user-principal', function (): void {
        [$service, $resolver] = makePrincipalService();
        $auth = bootAuthLayer();
        $userId = bootAuth($auth, 'ps-vis@example.com', PRINC_TEST_PASSWORD);
        $service->ensureUserPrincipal($userId);

        $ids = $resolver->visiblePrincipalIds($userId);
        expect($ids)->not->toBeEmpty();
    });
});
