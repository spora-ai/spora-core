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

    it('lets an agent key reach its own agent and nothing else', function (): void {
        $mine = AgentApiTestSupport::seedAgent('reach-mine');
        $theirs = AgentApiTestSupport::seedAgent('reach-theirs');

        $context = contextFor(ApiKeyScope::Agent, $mine['agentId']);

        expect(reach()->allows($context, $mine['agentId']))->toBeTrue();
        expect(reach()->allows($context, $theirs['agentId']))->toBeFalse();
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
