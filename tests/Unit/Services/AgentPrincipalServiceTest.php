<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Spora\Core\SecurityManager;
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
