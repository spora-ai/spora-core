<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Core\Config;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\RouteDefinitions;
use Spora\Core\Router;
use Spora\Security\CsrfTokenService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * The scoped API through the whole stack, in one request.
 *
 * The other scoped tests call the controller directly, through the bearer
 * middleware — which is the right level for "does this key reach that chat",
 * but it cannot tell "the route does not exist" from "the controller
 * refused", and it never exercises the route table at all.
 *
 * Here a real `Router` is built from the **full** `RouteDefinitions`, so the
 * route table, the feature gate, the bearer middleware, the controller and the
 * database are all in the path of one request. That is the layer where a
 * mistake is invisible to every other test: a bearer route registered without
 * its middleware, or with the wrong one, answers the same here as it does in
 * production.
 *
 * The XSRF property is pinned here from both sides, because it is the one
 * thing an integrator cannot work around: the bearer surfaces take no token at
 * all, and the admin surface takes one.
 */
describe('the scoped API over HTTP', function (): void {
    it('answers a request that carries a group key', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('http');
        [, $plaintext] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'http');

        $response = ScopedApiHttpSupport::router($container)->dispatch(
            ScopedApiHttpSupport::bearer($plaintext, '/api/v1/group-api/chats', 'GET'),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(ScopedApiHttpSupport::data($response))->toHaveKey('chats');
    });

    it('answers 401 without a credential', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        AgentApiTestSupport::seedGroup('http-noauth');

        $response = ScopedApiHttpSupport::router($container)->dispatch(
            Request::create('/api/v1/group-api/chats', 'GET'),
        );

        expect($response->getStatusCode())->toBe(401);
    });

    it('answers 401 for a key of the wrong scope', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('http-scope');
        [, $plaintext] = AgentApiTestSupport::mintGlobalKey('http-scope');

        // The global key reaches every group — but not through the group's
        // own surface. Each surface accepts exactly one scope, so a global
        // key is not a group key.
        $response = ScopedApiHttpSupport::router($container)->dispatch(
            ScopedApiHttpSupport::bearer($plaintext, '/api/v1/group-api/chats', 'GET'),
        );

        expect($response->getStatusCode())->toBe(401);

        // …and the group key is not a global key either.
        [, $groupKey] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'http-scope');

        $global = ScopedApiHttpSupport::router($container)->dispatch(
            ScopedApiHttpSupport::bearer($groupKey, '/api/v1/global-api/chats', 'GET'),
        );

        expect($global->getStatusCode())->toBe(401);
    });

    it('answers 404 for a personal agent on the global surface', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $personal = AgentApiTestSupport::seedAgent('http-personal');
        [, $plaintext] = AgentApiTestSupport::mintGlobalKey('http-personal');

        $response = ScopedApiHttpSupport::router($container)->dispatch(
            ScopedApiHttpSupport::bearer(
                $plaintext,
                '/api/v1/global-api/chats',
                'POST',
                ['agent_id' => $personal['agentId'], 'text' => 'Hello there.'],
            ),
        );

        expect($response->getStatusCode())->toBe(404);
    });

    it('takes no CSRF token on a bearer surface', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('http-csrf');
        [, $plaintext] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'http-csrf');

        $request = ScopedApiHttpSupport::bearer(
            $plaintext,
            '/api/v1/group-api/chats',
            'POST',
            ['agent_id' => $group['agentIds'][0], 'text' => 'Hello there.'],
        );

        // No `X-CSRF-Token` header, and no session to take one from: the
        // bearer key is the only credential this surface accepts.
        expect($request->headers->get('X-CSRF-Token'))->toBeNull();

        $response = ScopedApiHttpSupport::router($container)->dispatch($request);

        expect($response->getStatusCode())->toBe(202);
    });

    it('takes a CSRF token on the admin surface', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('http-admin');
        simulateLoggedInSession($group['userId'], 'scoped-http@example.com');

        $path = "/api/v1/groups/{$group['groupId']}/api-keys";

        // Without a token the write is refused — the admin surface is the
        // session's, and a session is what a token protects.
        $withoutToken = ScopedApiHttpSupport::router($container)->dispatch(
            ScopedApiHttpSupport::json($path, 'POST', ['name' => 'no-token']),
        );

        expect($withoutToken->getStatusCode())->toBe(403);
        expect(ScopedApiHttpSupport::errorCode($withoutToken))->toBe('CSRF_TOKEN_MISSING');

        // With one, it is minted. The bearer surfaces take no token at all,
        // and the admin surface takes one: the two stacks are different, and
        // that difference is deliberate.
        $token = $container->get(CsrfTokenService::class)->generate();

        $withToken = ScopedApiHttpSupport::router($container)->dispatch(
            ScopedApiHttpSupport::json($path, 'POST', ['name' => 'with-token'], ['X-CSRF-Token' => $token]),
        );

        expect($withToken->getStatusCode())->toBe(201);
        expect(ScopedApiHttpSupport::data($withToken))->toHaveKey('key');
    });

    it('answers 405 for minting while the scope is off, and 201 while it is on', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('http-gate');
        simulateLoggedInSession($group['userId'], 'scoped-http-gate@example.com');

        $token = $container->get(CsrfTokenService::class)->generate();
        $path = "/api/v1/groups/{$group['groupId']}/api-keys";

        // The scope is off in the container's config, so the mint route is
        // not mounted: the path still answers for listing and revoking, and
        // refuses the method.
        $off = ScopedApiHttpSupport::router($container, ['SPORA_GROUP_API' => '0'])->dispatch(
            ScopedApiHttpSupport::json($path, 'POST', ['name' => 'off'], ['X-CSRF-Token' => $token]),
        );

        expect($off->getStatusCode())->toBe(405);

        // With the scope on, the same request mints.
        $on = ScopedApiHttpSupport::router($container)->dispatch(
            ScopedApiHttpSupport::json($path, 'POST', ['name' => 'on'], ['X-CSRF-Token' => $token]),
        );

        expect($on->getStatusCode())->toBe(201);
    });
});

final class ScopedApiHttpSupport
{
    /**
     * A router over the application's whole route table.
     *
     * The full table, not only the scoped one: a route registered by the
     * wrong file, or with the wrong middleware, has to show up here.
     *
     * @param array<string, string> $config
     */
    public static function router(\DI\Container $container, array $config = []): Router
    {
        $settings = $config === []
            ? $container->get(Config::class)
            : new Config($config + ['SPORA_LOG_LEVEL' => 'error']);

        return new Router($container, static function (MiddlewareRouteCollector $r) use ($settings): void {
            RouteDefinitions::register($r, $settings);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function bearer(string $key, string $path, string $method, array $body = []): Request
    {
        return self::json($path, $method, $body, ['Authorization' => 'Bearer ' . $key]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(string $path, string $method, array $body = [], array $headers = []): Request
    {
        return jsonRequest($method, $path, $body, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(Response $response): array
    {
        $body = $response->getContent();

        $decoded = $body === '' ? [] : (json_decode($body, true) ?: []);

        return is_array($decoded) ? ($decoded['data'] ?? []) : [];
    }

    public static function errorCode(Response $response): ?string
    {
        $body = $response->getContent();

        $decoded = $body === '' ? [] : (json_decode($body, true) ?: []);

        $code = $decoded['error']['code'] ?? null;

        return is_string($code) ? $code : null;
    }
}
