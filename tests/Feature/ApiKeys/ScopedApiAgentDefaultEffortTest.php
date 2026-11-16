<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Http\ScopedApi\ScopedApiAgentController;
use Spora\Models\Agent;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * The agent's own reasoning-effort default on the scoped surfaces.
 *
 * The payload is the Agent Template format, so the field travels inside
 * the `agent` block. Two states only: null means "inherit", a level name
 * means "run at this level" — and a level the agent's configuration does
 * not offer is refused rather than stored.
 */
describe('the agent default effort on the group surface', function (): void {
    it('round-trips through the scoped agent PATCH', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('effort-roundtrip');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'effort');
        $agentId = $group['agentIds'][0];

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'update',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $agentId,
                ['agent' => ['default_effort' => 'high']],
                'PATCH',
            ),
            [$agentId],
        );

        expect($response->getStatusCode())->toBe(200)
            ->and(Agent::findOrFail($agentId)->default_effort)->toBe('high');
    });

    it('refuses a default the agent\'s driver does not offer', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('effort-refused');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'effort');
        $agentId = $group['agentIds'][0];

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'update',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $agentId,
                ['agent' => ['default_effort' => 'turbo']],
                'PATCH',
            ),
            [$agentId],
        );

        expect($response->getStatusCode())->toBe(422)
            ->and(ScopedApiTestSupport::errorCode($response))->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and(Agent::findOrFail($agentId)->default_effort)->toBeNull();
    });

    it('clears the default with a null', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('effort-cleared');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'effort');
        $agentId = $group['agentIds'][0];

        Agent::findOrFail($agentId)->forceFill(['default_effort' => 'high'])->save();

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'update',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $agentId,
                ['agent' => ['default_effort' => null]],
                'PATCH',
            ),
            [$agentId],
        );

        expect($response->getStatusCode())->toBe(200)
            ->and(Agent::findOrFail($agentId)->default_effort)->toBeNull();
    });
});
