<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Psr\Container\ContainerInterface;
use Spora\Core\RouteDefinitions;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Http\AgentApi\AgentApiEffortController;
use Spora\OpenApi\RouteSpecCollector;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/v1/agent-api/effort` — the levels the key's own agent offers.
 *
 * The key pins one agent, so the path carries no id: the answer is always
 * about the agent the key was minted on, and a client holding a key never
 * has to guess which levels exist.
 */
describe('the effort endpoint of the agent API', function (): void {
    it('reports the levels, their labels and the default of its own agent', function (): void {
        $seed = AgentApiTestSupport::seedAgent('effort-levels');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort-levels');

        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiEffortController::class,
            'show',
            effortEndpointRequest($key),
        );

        expect($response->getStatusCode())->toBe(200);

        $data = AgentApiTestSupport::decode($response)['data'];

        expect($data['supported'])->toBeTrue()
            ->and($data['default'])->toBeNull()
            ->and(array_column($data['levels'], 'value'))->toContain('low', 'high')
            ->and(array_column($data['levels'], 'label'))->toContain('Low', 'High');
    });

    it('answers for the agent its key names, and not for another one', function (): void {
        $anthropic = AgentApiTestSupport::seedAgent('effort-anthropic', AnthropicCompatibleDriver::class);
        $openai = AgentApiTestSupport::seedAgent('effort-openai');
        [, $anthropicKey] = AgentApiTestSupport::mintKey($anthropic['agentId'], 'effort-anthropic');
        [, $openaiKey] = AgentApiTestSupport::mintKey($openai['agentId'], 'effort-openai');

        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());

        $anthropicLevels = effortEndpointLevels($container, $anthropicKey);
        $openaiLevels = effortEndpointLevels($container, $openaiKey);

        // The Anthropic-compatible driver takes no `none`; the OpenAI one
        // takes the whole canonical set. Two keys, two answers — the key
        // decides which agent is described.
        expect($anthropicLevels)->toContain('low', 'high')
            ->and($anthropicLevels)->not->toContain('none')
            ->and($openaiLevels)->toContain('none')
            ->and($anthropicLevels)->not->toBe($openaiLevels);
    });

    it('refuses a key of another scope', function (): void {
        $seed = AgentApiTestSupport::seedAgent('effort-wrong-scope');
        [, $ownKey] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort');
        $group = AgentApiTestSupport::seedGroup('effort-wrong-scope-group');
        [, $groupKey] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'effort');

        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());

        // The surface answers for its own key …
        expect(AgentApiTestSupport::call(
            $container,
            AgentApiEffortController::class,
            'show',
            effortEndpointRequest($ownKey),
        )->getStatusCode())->toBe(200);

        // … and refuses a key minted for another scope.
        $response = AgentApiTestSupport::call(
            $container,
            AgentApiEffortController::class,
            'show',
            effortEndpointRequest($groupKey),
        );

        expect($response->getStatusCode())->toBe(401);
    });

    it('refuses a key nobody minted', function (): void {
        $seed = AgentApiTestSupport::seedAgent('effort-unknown-key');
        [, $ownKey] = AgentApiTestSupport::mintKey($seed['agentId'], 'effort-unknown-key');

        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());

        expect(AgentApiTestSupport::call(
            $container,
            AgentApiEffortController::class,
            'show',
            effortEndpointRequest($ownKey),
        )->getStatusCode())->toBe(200);

        $response = AgentApiTestSupport::call(
            $container,
            AgentApiEffortController::class,
            'show',
            effortEndpointRequest('spk_1_not-a-real-key'),
        );

        expect($response->getStatusCode())->toBe(401);
    });

    it('registers the route', function (): void {
        $collector = new RouteSpecCollector();
        RouteDefinitions::register($collector);

        $routes = array_map(
            static fn(array $route): string => $route['method'] . ' ' . $route['route'],
            $collector->routes(),
        );

        expect($routes)->toContain('GET /api/v1/agent-api/effort');
    });
});

function effortEndpointRequest(string $key): Request
{
    return Request::create(
        '/api/v1/agent-api/effort',
        'GET',
        server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $key],
    );
}

/**
 * The level names the endpoint reports for the key's own agent.
 *
 * @return list<string>
 */
function effortEndpointLevels(ContainerInterface $container, string $key): array
{
    $response = AgentApiTestSupport::call(
        $container,
        AgentApiEffortController::class,
        'show',
        effortEndpointRequest($key),
    );

    expect($response->getStatusCode())->toBe(200);

    return array_column(AgentApiTestSupport::decode($response)['data']['levels'], 'value');
}
