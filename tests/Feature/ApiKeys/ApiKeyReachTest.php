<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Auth\ApiKeyScope;
use Spora\Models\Agent;
use Spora\Models\ApiKey;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyReach;
use Spora\Services\PrincipalResolver;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * What a credential may touch.
 *
 * This is the load-bearing rule of the whole feature: a group key that
 * could see one agent too many is a breach, and a global key that could
 * see a personal agent is a different one.
 */
describe('the reach of a key', function (): void {
    function reach(): ApiKeyReach
    {
        return new ApiKeyReach(new PrincipalResolver());
    }

    function contextFor(ApiKeyScope $scope, ?int $agentId = null, ?int $groupId = null): ApiKeyContext
    {
        return new ApiKeyContext(
            new ApiKey(['scope' => $scope->value, 'agent_id' => $agentId, 'group_id' => $groupId]),
            $scope,
            $agentId === null ? null : Agent::findOrFail($agentId),
            $agentId === null ? null : 1,
            $groupId,
        );
    }

    /**
     * An agent that shares a principal with the ones before it, and whose
     * own id is not that principal's id.
     *
     * `seedAgent()` creates one user, one principal and one agent each, so
     * the nth agent it makes carries the nth principal id — and a rule that
     * filtered on the wrong column would then match by coincidence. The
     * decoy shifts the agent ids out of the principal id space, so the
     * assertions below can only pass for the right reason.
     */
    function seedAgentOffThePrincipal(int $principalId, string $name): int
    {
        return (int) Agent::create([
            'principal_id' => $principalId,
            'name' => $name,
            'max_steps' => 10,
            'is_active' => true,
        ])->id;
    }

    it('lets an agent key reach its own agent and nothing else', function (): void {
        $owner = bootAuthLayer();
        $userId = $owner->register('reach-owner@example.com', 'Password1!', 'Reach Owner');
        $principalId = createUserPrincipalPublic($userId);

        // The decoy: it takes agent id 1, so the two agents under test get
        // ids 2 and 3 — neither of them the principal's id.
        seedAgentOffThePrincipal($principalId, 'Decoy');
        $mine = seedAgentOffThePrincipal($principalId, 'Mine');
        $theirs = seedAgentOffThePrincipal($principalId, 'Theirs');

        expect($mine)->not->toBe($principalId);

        $context = contextFor(ApiKeyScope::Agent, $mine);

        expect(reach()->allows($context, $mine))->toBeTrue();
        expect(reach()->allows($context, $theirs))->toBeFalse();
    });

    it('lets a group key reach every agent of its group and no other', function (): void {
        $group = AgentApiTestSupport::seedGroup('reach-group', 2);
        $other = AgentApiTestSupport::seedGroup('reach-other', 1);
        $personal = AgentApiTestSupport::seedAgent('reach-personal');

        $context = contextFor(ApiKeyScope::Group, null, $group['groupId']);

        foreach ($group['agentIds'] as $agentId) {
            expect(reach()->allows($context, $agentId))->toBeTrue();
        }

        expect(reach()->allows($context, $other['agentIds'][0]))->toBeFalse();
        expect(reach()->allows($context, $personal['agentId']))->toBeFalse();
    });

    it('lets a global key reach every group-owned agent but no personal one', function (): void {
        $first = AgentApiTestSupport::seedGroup('reach-global-a', 1);
        $second = AgentApiTestSupport::seedGroup('reach-global-b', 1);
        $personal = AgentApiTestSupport::seedAgent('reach-global-personal');

        $context = contextFor(ApiKeyScope::Global);

        expect(reach()->allows($context, $first['agentIds'][0]))->toBeTrue();
        expect(reach()->allows($context, $second['agentIds'][0]))->toBeTrue();
        expect(reach()->allows($context, $personal['agentId']))->toBeFalse();
    });

    it('reaches nothing when a group key names a group that has no principal', function (): void {
        $context = contextFor(ApiKeyScope::Group, null, 999999);

        expect(reach()->agents($context)->count())->toBe(0);
    });
});
