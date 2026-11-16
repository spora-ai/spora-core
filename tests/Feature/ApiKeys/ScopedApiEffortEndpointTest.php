<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Core\RouteDefinitions;
use Spora\Http\ScopedApi\ScopedApiEffortController;
use Spora\OpenApi\RouteSpecCollector;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * `GET /api/v1/group-api/agents/{agentId}/effort` and its global twin.
 *
 * The agent is named in the path, so the reach decides whether it exists
 * as far as the caller is concerned: an agent outside the reach is a 404,
 * never a 403, so a caller cannot probe which agent ids exist.
 */
describe('the effort endpoint of the scoped API', function (): void {
    it('reports the levels of an agent the group key reaches', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('effort-group');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'effort');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $group['agentIds'][0] . '/effort',
                [],
                'GET',
            ),
            [$group['agentIds'][0]],
        );

        expect($response->getStatusCode())->toBe(200);

        $data = ScopedApiTestSupport::data($response);
        expect($data['supported'])->toBeTrue()
            ->and(array_column($data['levels'], 'value'))->toContain('low', 'high')
            ->and(array_column($data['levels'], 'label'))->toContain('Low', 'High');
    });

    it('reports the levels of a group-owned agent on the global surface', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('effort-global');
        [, $key] = AgentApiTestSupport::mintGlobalKey('effort');

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/agents/' . $group['agentIds'][0] . '/effort',
                [],
                'GET',
            ),
            [$group['agentIds'][0]],
        );

        expect($response->getStatusCode())->toBe(200);

        $data = ScopedApiTestSupport::data($response);
        expect($data['supported'])->toBeTrue()
            ->and(array_column($data['levels'], 'value'))->toContain('low', 'high');
    });

    it('404s an agent outside the group key\'s reach', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $mine = AgentApiTestSupport::seedGroup('effort-reach-mine');
        $theirs = AgentApiTestSupport::seedGroup('effort-reach-theirs');
        [, $key] = AgentApiTestSupport::mintGroupKey($mine['groupId'], 'effort');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $theirs['agentIds'][0] . '/effort',
                [],
                'GET',
            ),
            [$theirs['agentIds'][0]],
        );

        expect($response->getStatusCode())->toBe(404);
        expect(ScopedApiTestSupport::errorCode($response))->toBe('AGENT_NOT_FOUND');
    });

    it('404s a personal agent on the global surface', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $personal = AgentApiTestSupport::seedAgent('effort-personal');
        [, $key] = AgentApiTestSupport::mintGlobalKey('effort');

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/agents/' . $personal['agentId'] . '/effort',
                [],
                'GET',
            ),
            [$personal['agentId']],
        );

        expect($response->getStatusCode())->toBe(404);
        expect(ScopedApiTestSupport::errorCode($response))->toBe('AGENT_NOT_FOUND');
    });

    it('refuses a group key on the global surface', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('effort-wrong-scope');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'effort');

        // The group surface answers for the same key …
        expect(ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $group['agentIds'][0] . '/effort',
                [],
                'GET',
            ),
            [$group['agentIds'][0]],
        )->getStatusCode())->toBe(200);

        // … and the global surface refuses it: a group key is not a global one.
        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/agents/' . $group['agentIds'][0] . '/effort',
                [],
                'GET',
            ),
            [$group['agentIds'][0]],
        );

        expect($response->getStatusCode())->toBe(401);
    });

    it('registers both routes', function (): void {
        $collector = new RouteSpecCollector();
        RouteDefinitions::register($collector);

        $routes = array_map(
            static fn(array $route): string => $route['method'] . ' ' . $route['route'],
            $collector->routes(),
        );

        expect($routes)->toContain(
            'GET /api/v1/group-api/agents/{agentId}/effort',
            'GET /api/v1/global-api/agents/{agentId}/effort',
        );
    });
});
