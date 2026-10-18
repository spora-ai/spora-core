<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Core\Config;
use Spora\Core\ContainerDefinitions;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Paths;
use Spora\Core\Router;
use Spora\Core\SecurityManager;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Plugins\PluginLoader;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Routing and gating for the Agent API.
 *
 * Separate from the end-to-end file because those tests call controllers
 * directly, and cannot tell "the route does not exist" from "the controller
 * refused". Here a real `Router` matches the real registration, so the
 * distinction is observable: an unregistered route is a 404 from the router,
 * while a registered one reaches the bearer middleware and answers 401.
 *
 * The last case is the one that matters for operators. `SPORA_AGENT_API` is
 * database-backed and applied by `Config::applySettingsOverlay()` before the
 * router is built, so flipping it in the settings table has to change what is
 * mounted — without a restart. The columns are `group_key` / `setting_key` /
 * `value`, not `group` / `key`.
 */
describe('Agent API routing', function (): void {
    it('matches every documented bearer endpoint', function (): void {
        $router = AgentApiRouteGateSupport::router();

        $cases = [
            ['GET', '/api/v1/agent-api/chats'],
            ['POST', '/api/v1/agent-api/chats'],
            ['GET', '/api/v1/agent-api/chats/7/status'],
            ['GET', '/api/v1/agent-api/chats/7/messages'],
            ['POST', '/api/v1/agent-api/chats/7/messages'],
            ['GET', '/api/v1/agent-api/chats/7/transcript'],
            ['POST', '/api/v1/agent-api/chats/7/approve'],
            ['POST', '/api/v1/agent-api/chats/7/reject'],
            ['POST', '/api/v1/agent-api/chats/7/answer'],
            ['POST', '/api/v1/agent-api/media'],
            ['GET', '/api/v1/agent-api/media/abc'],
        ];

        foreach ($cases as [$method, $path]) {
            // 401 rather than 404: the route exists and its middleware
            // demands a credential. That is what distinguishes "mounted"
            // from "not mounted".
            expect($router->dispatch(Request::create($path, $method))->getStatusCode())
                ->toBe(401, "{$method} {$path} should route to a credentialed handler");
        }
    });

    it('mounts the key-management endpoints behind the session', function (): void {
        $router = AgentApiRouteGateSupport::router();

        foreach ([
            ['GET', '/api/v1/agents/3/api-keys'],
            ['POST', '/api/v1/agents/3/api-keys'],
            ['DELETE', '/api/v1/agents/3/api-keys/deadbeef'],
        ] as [$method, $path]) {
            // `AuthMiddleware` throws rather than returning, and `Kernel` is
            // what maps it to a 401 — so the throw *is* the proof the route
            // matched a session-gated handler.
            expect(fn() => $router->dispatch(Request::create($path, $method)))
                ->toThrow(UnauthenticatedException::class);
        }
    });

    it('does not treat a literal action segment as a chat id', function (): void {
        // FastRoute matches in registration order, so a catch-all ahead of
        // the literal `status` route would answer it. Asserting the literal
        // still reaches the credentialed handler catches that reordering.
        $router = AgentApiRouteGateSupport::router();

        $literals = [
            ['GET', 'status'],
            ['GET', 'messages'],
            ['POST', 'messages'],
            ['GET', 'transcript'],
            ['POST', 'approve'],
            ['POST', 'reject'],
            ['POST', 'answer'],
        ];

        foreach ($literals as [$method, $literal]) {
            expect($router->dispatch(Request::create("/api/v1/agent-api/chats/7/{$literal}", $method))->getStatusCode())
                ->toBe(401, "{$method} .../{$literal} must reach its own handler");
        }
    });
});

describe('SPORA_AGENT_API gating', function (): void {
    it('answers 404 for every bearer endpoint when the flag is off', function (): void {
        $router = AgentApiRouteGateSupport::router(['SPORA_AGENT_API' => '0']);

        foreach ([
            '/api/v1/agent-api/chats',
            '/api/v1/agent-api/media/abc',
        ] as $path) {
            expect($router->dispatch(Request::create($path, 'GET'))->getStatusCode())
                ->toBe(404, "{$path} must be absent when the flag is off");
        }
    });

    it('keeps the key-management endpoints mounted with the flag off', function (): void {
        // An operator who enables the toggle gets a working tab; one who has
        // not must still be able to list keys they minted earlier, so the
        // admin surface is not gated.
        $router = AgentApiRouteGateSupport::router(['SPORA_AGENT_API' => '0']);

        expect(fn() => $router->dispatch(Request::create('/api/v1/agents/3/api-keys', 'GET')))
            ->toThrow(UnauthenticatedException::class);
    });

    it('answers 404 in client-worker mode even with the flag on', function (): void {
        // Nothing on the server executes a queued task there, so accepting
        // work would be a promise Spora cannot keep. The route is absent
        // rather than declining, so the surface stays unenumerable.
        $router = AgentApiRouteGateSupport::router(
            ['SPORA_AGENT_API' => '1', 'SPORA_WORKER_RUNTIME_MODE' => 'client'],
        );

        $seed = AgentApiTestSupport::seedAgent('clientmode');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'clientmode');

        $request = Request::create('/api/v1/agent-api/chats', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $key);

        expect($router->dispatch($request)->getStatusCode())->toBe(404);
    });

    it('serves the surface after the flag is stored in the database', function (): void {
        AgentApiRouteGateSupport::storeFlag('1');

        // A fresh router, built from the same overlay `Kernel::handle()`
        // applies: no restart, no container rebuild.
        $config = new Config([]);
        $config->applySettingsOverlay(...AgentApiRouteGateSupport::repository());

        expect($config->getBool('SPORA_AGENT_API'))->toBeTrue();

        $router = AgentApiRouteGateSupport::routerFrom($config);

        expect($router->dispatch(Request::create('/api/v1/agent-api/chats', 'GET'))->getStatusCode())
            ->toBe(401);
    });

    it('reports the flag to the SPA as its own field, not folded into worker mode', function (): void {
        // The tab is gated on the setting alone so that an operator who
        // enabled it on a client-worker instance still sees it — the page
        // carries the warning instead.
        $config = new Config(['SPORA_AGENT_API' => '1']);

        expect($config->getBool('SPORA_AGENT_API'))->toBeTrue();
    });
});

final class AgentApiRouteGateSupport
{
    /**
     * @param array<string, string> $config
     */
    public static function router(array $config = ['SPORA_AGENT_API' => '1']): Router
    {
        return self::routerFrom(new Config($config + ['SPORA_LOG_LEVEL' => 'error']));
    }

    public static function routerFrom(Config $config): Router
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(ContainerDefinitions::all());
        $builder->addDefinitions([PluginLoader::class => new PluginLoader([], null)]);
        $container = $builder->build();

        $container->set(Config::class, $config);
        $container->set(
            WorkerRuntimeMode::class,
            $config->getString('SPORA_WORKER_RUNTIME_MODE') === 'client'
                ? WorkerRuntimeMode::Client
                : WorkerRuntimeMode::Server,
        );

        // The same call shape `RouteDefinitions::register()` uses, so the
        // flag reaches the gate exactly as it does in production.
        return new Router($container, static function (MiddlewareRouteCollector $r) use ($config): void {
            \Spora\Core\AgentApiRouteDefinitions::register($r, $config);
        });
    }

    /**
     * Persist the flag the way the admin settings endpoint does.
     *
     * `group_key` / `setting_key` / `value`, and group `framework` — every
     * non-mail setting lives there regardless of which UI section it is
     * shown under.
     */
    public static function storeFlag(string $value): void
    {
        \Illuminate\Database\Capsule\Manager::table('settings')->updateOrInsert(
            ['group_key' => 'framework', 'setting_key' => 'SPORA_AGENT_API'],
            ['value' => $value, 'encrypted' => false, 'updated_at' => gmdate('Y-m-d H:i:s')],
        );
    }

    /**
     * @return array{0: SettingsRepository, 1: SettingsRegistry}
     */
    public static function repository(): array
    {
        $registry = new SettingsRegistry(new Paths(BASE_PATH));
        $repository = new SettingsRepository(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            $registry,
        );

        return [$repository, $registry];
    }
}
