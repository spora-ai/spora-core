<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\AgentPrincipalService;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Tools\HandoverTool;
use Tests\Fixtures\TestTool;

defined('PRINC_TEST_PASSWORD') || define('PRINC_TEST_PASSWORD', 'Password1!');

/**
 * AgentPrincipalService — covers the post-transfer handover-allowlist
 * prune. The intra-principal gate at the tool level (`sharePrincipal`)
 * is already enforced at runtime; this test pins the new behaviour that
 * keeps the stored allowlist in sync with the agent's new principal
 * so the LLM-facing tool definition is honest about what it can target.
 */

function makeAgentPrincipalService(): array
{
    $auth = bootAuthLayer();
    $resolver = new PrincipalResolver();
    $principalService = new PrincipalService($resolver);
    $groupService = new GroupService($principalService);

    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger = new Monolog\Logger('test');
    $toolConfig = new ToolConfigService($security, $logger, [TestTool::class]);

    $service = new AgentPrincipalService($principalService, $toolConfig);

    return [$service, $auth, $principalService, $groupService, $toolConfig];
}

function seedAgentForTest(int $principalId, string $name): int
{
    return (int) Capsule::table('agents')->insertGetId([
        'principal_id'         => $principalId,
        'name'                 => $name,
        'description'          => null,
        'llm_driver_config_id' => null,
        'max_steps'            => 10,
        'is_active'            => 1,
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ]);
}

describe('AgentPrincipalService::transferAgent handover-allowlist prune', function (): void {
    it('drops stale targets from the per-agent override on transfer', function (): void {
        // Two group-principals owned by the same caller — satisfies
        // `callerControlsPrincipal` on both sides of the transfer.
        [$service, $auth, $principalService, $groupService, $toolConfig] = makeAgentPrincipalService();
        $callerId = bootAuth($auth, 'aps-prune-caller@example.com', PRINC_TEST_PASSWORD);

        $groupA = $groupService->createGroup($callerId, 'A');
        $groupB = $groupService->createGroup($callerId, 'B');
        $principalA = $principalService->ensureGroupPrincipal($groupA->id);
        $principalB = $principalService->ensureGroupPrincipal($groupB->id);
        expect((int) $principalA->id)->not->toBe((int) $principalB->id);

        // Source agent + three potential handover targets: two in
        // principal A, one in principal B.
        $sourceAgent = seedAgentForTest((int) $principalA->id, 'Source');
        $inA1 = seedAgentForTest((int) $principalA->id, 'InA1');
        $inA2 = seedAgentForTest((int) $principalA->id, 'InA2');
        $inB = seedAgentForTest((int) $principalB->id, 'InB');

        // Operator's pre-transfer config: in-A targets AND a cross-A
        // target. After transfer to B, only $inB is valid.
        $toolConfig->putAgentOverride(HandoverTool::class, $sourceAgent, [
            'allowed_target_agents' => [$inA1, $inA2, $inB],
        ]);

        $service->transferAgent(
            $sourceAgent,
            (int) $principalB->id,
            $callerId,
        );

        $stored = $toolConfig->getRawAgentOverride(HandoverTool::class, $sourceAgent);
        expect($stored['allowed_target_agents'])->toBe([$inB]);
    })->afterEach(fn() => Database::resetBootState());

    it('does not touch the override when the agent had no handover allowlist', function (): void {
        [$service, $auth, $principalService, $groupService, $toolConfig] = makeAgentPrincipalService();
        $callerId = bootAuth($auth, 'aps-prune-empty@example.com', PRINC_TEST_PASSWORD);

        $groupA = $groupService->createGroup($callerId, 'EmptyA');
        $groupB = $groupService->createGroup($callerId, 'EmptyB');
        $principalA = $principalService->ensureGroupPrincipal($groupA->id);
        $principalB = $principalService->ensureGroupPrincipal($groupB->id);

        $sourceAgent = seedAgentForTest((int) $principalA->id, 'NoOpSrc');

        // A different tool's override that must survive untouched.
        $toolConfig->putAgentOverride(TestTool::class, $sourceAgent, [
            'max_results' => '99',
        ]);

        $service->transferAgent(
            $sourceAgent,
            (int) $principalB->id,
            $callerId,
        );

        $stored = $toolConfig->getRawAgentOverride(TestTool::class, $sourceAgent);
        expect($stored['max_results'])->toBe('99');
    })->afterEach(fn() => Database::resetBootState());
});

/**
 * pruneHandoverAllowlist — directly tested so each short-circuit
 * (no override row, every target shares the new principal, no
 * `resolveAs=agent` settings) is pinned without going through the
 * full transfer flow. The two integration tests above cover the
 * happy path of the transfer → prune sequence; the cases here cover
 * the prune method's defensive branches.
 */
describe('AgentPrincipalService::pruneHandoverAllowlist', function (): void {
    it('drops ids that do not share the new principal', function (): void {
        [$service, $auth, , , $toolConfig] = makeAgentPrincipalService();

        $userA = $auth->register('aps-prune-a@example.com', PRINC_TEST_PASSWORD, 'A');
        $userB = $auth->register('aps-prune-b@example.com', PRINC_TEST_PASSWORD, 'B');
        $principalA = createUserPrincipalPublic($userA);
        $principalB = createUserPrincipalPublic($userB);

        $sourceAgent = Agent::create([
            'principal_id' => $principalA,
            'name'         => 'Source',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;
        $targetInA = Agent::create([
            'principal_id' => $principalA,
            'name'         => 'TargetInA',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;
        $targetInB = Agent::create([
            'principal_id' => $principalB,
            'name'         => 'TargetInB',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;

        $toolConfig->putAgentOverride(HandoverTool::class, $sourceAgent, [
            'allowed_target_agents' => [$targetInA, $targetInB, 999_999],
            'max_results'           => '20',
        ]);

        $removed = $service->pruneHandoverAllowlist($sourceAgent, $principalB);

        expect($removed)->toBe(2);
        $stored = $toolConfig->getRawAgentOverride(HandoverTool::class, $sourceAgent);
        expect($stored['allowed_target_agents'])->toBe([$targetInB]);
        // Non-agent-id settings are untouched.
        expect($stored['max_results'])->toBe('20');
    })->afterEach(fn() => Database::resetBootState());

    it('is a no-op when no override exists', function (): void {
        [$service] = makeAgentPrincipalService();
        expect($service->pruneHandoverAllowlist(999_999, 42))->toBe(0);
    })->afterEach(fn() => Database::resetBootState());

    it('keeps every target when they all share the new principal', function (): void {
        [$service, $auth, , , $toolConfig] = makeAgentPrincipalService();
        $userId = $auth->register('aps-prune-keep@example.com', PRINC_TEST_PASSWORD, 'Keep');
        $principal = createUserPrincipalPublic($userId);

        $source = Agent::create([
            'principal_id' => $principal,
            'name'         => 'Source',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;
        $t1 = Agent::create(['principal_id' => $principal, 'name' => 'T1', 'llm_provider' => 'mock', 'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true])->id;
        $t2 = Agent::create(['principal_id' => $principal, 'name' => 'T2', 'llm_provider' => 'mock', 'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true])->id;

        $toolConfig->putAgentOverride(HandoverTool::class, $source, [
            'allowed_target_agents' => [$t1, $t2],
        ]);

        expect($service->pruneHandoverAllowlist($source, $principal))->toBe(0);
        expect($toolConfig->getRawAgentOverride(HandoverTool::class, $source)['allowed_target_agents'])
            ->toBe([$t1, $t2]);
    })->afterEach(fn() => Database::resetBootState());

    it('is a no-op when the override has no agent-id keys', function (): void {
        [$service, $auth, , , $toolConfig] = makeAgentPrincipalService();
        $userId = $auth->register('aps-prune-noids@example.com', PRINC_TEST_PASSWORD, 'NoIds');
        $principal = createUserPrincipalPublic($userId);

        $agentId = Agent::create([
            'principal_id' => $principal,
            'name'         => 'NoIds',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;

        // Override without any multi-select `resolveAs=agent` keys.
        $toolConfig->putAgentOverride(HandoverTool::class, $agentId, [
            'max_results' => '15',
        ]);

        expect($service->pruneHandoverAllowlist($agentId, $principal))->toBe(0);
        expect($toolConfig->getRawAgentOverride(HandoverTool::class, $agentId)['max_results'])->toBe('15');
    })->afterEach(fn() => Database::resetBootState());
});
