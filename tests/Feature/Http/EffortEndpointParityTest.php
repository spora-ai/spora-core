<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Http\AgentApi\AgentApiEffortController;
use Spora\Http\AgentEffortController;
use Spora\Http\ScopedApi\ScopedApiEffortController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\AgentApi\AgentApiTestSupport;
use Tests\Feature\ApiKeys\ScopedApiTestSupport;

/**
 * The four effort endpoints report the same agent the same way.
 *
 * They are four surfaces over one payload, so they answer through one
 * projection. The assertion is on the answers themselves rather than on
 * the projection: a copy per endpoint would drift, and the drift would
 * only show up in a client that compares the two.
 */
describe('the effort endpoints', function (): void {
    it('answer with the same payload for the same agent', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('effort-parity');
        $agentId = $group['agentIds'][0];

        [, $agentKey] = AgentApiTestSupport::mintKey($agentId, 'effort-parity');
        [, $groupKey] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'effort-parity');
        [, $globalKey] = AgentApiTestSupport::mintGlobalKey('effort-parity');

        // The admin endpoint is the cookie-authenticated one, so the group
        // owner is the caller.
        simulateLoggedInSession($group['userId'], 'effort-parity@example.com');

        $admin = $container->get(AgentEffortController::class)->show(
            effortAdminRequest($agentId),
        );

        $agent = AgentApiTestSupport::call(
            $container,
            AgentApiEffortController::class,
            'show',
            effortBearerRequest('/api/v1/agent-api/effort', $agentKey),
        );

        $grouped = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $groupKey,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $agentId . '/effort',
                [],
                'GET',
            ),
            [$agentId],
        );

        $global = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiEffortController::class,
            'show',
            ScopedApiTestSupport::bearer(
                $globalKey,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/agents/' . $agentId . '/effort',
                [],
                'GET',
            ),
            [$agentId],
        );

        $payloads = array_map(
            static fn(Response $response): array => [
                'status' => $response->getStatusCode(),
                'data' => json_decode((string) $response->getContent(), true)['data'] ?? null,
            ],
            [$admin, $agent, $grouped, $global],
        );

        expect($payloads[0]['status'])->toBe(200)
            ->and($payloads[1]['status'])->toBe(200)
            ->and($payloads[2]['status'])->toBe(200)
            ->and($payloads[3]['status'])->toBe(200);

        expect($payloads[1]['data'])->toBe($payloads[0]['data'])
            ->and($payloads[2]['data'])->toBe($payloads[0]['data'])
            ->and($payloads[3]['data'])->toBe($payloads[0]['data']);

        expect($payloads[0]['data'])->toHaveKeys(['supported', 'levels', 'default'])
            ->and($payloads[0]['data']['supported'])->toBeTrue()
            ->and($payloads[0]['data']['default'])->toBeNull()
            ->and(array_column($payloads[0]['data']['levels'], 'value'))->toContain('low', 'high')
            ->and(array_column($payloads[0]['data']['levels'], 'label'))->toContain('Low', 'High');
    });
});

function effortAdminRequest(int $agentId): Request
{
    $request = Request::create('/api/v1/agents/' . $agentId . '/effort', 'GET');
    $request->attributes->set('id', $agentId);

    return $request;
}

function effortBearerRequest(string $path, string $key): Request
{
    return Request::create(
        $path,
        'GET',
        server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $key],
    );
}
