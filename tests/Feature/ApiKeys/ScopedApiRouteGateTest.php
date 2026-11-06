<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

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
 * Routing and gating for the scoped API surfaces.
 *
 * Separate from the end-to-end files because those call controllers
 * directly, and cannot tell "the route does not exist" from "the controller
 * refused". Here a real `Router` matches the real registration, so the
 * distinction is observable: an unregistered route is a 404 from the router,
 * while a registered one reaches the bearer middleware and answers 401.
 *
 * The three scopes are independent: switching one on must not mount
 * another, or an operator who enabled the group surface would silently get
 * the install-wide one too.
 */
describe('scoped API routing', function (): void {
    it('matches every documented group endpoint', function (): void {
        $router = ScopedApiRouteGateSupport::router();

        foreach ([
            ['GET', '/api/v1/group-api/group'],
            ['GET', '/api/v1/group-api/agents'],
            ['POST', '/api/v1/group-api/agents'],
            ['GET', '/api/v1/group-api/agents/7'],
            ['PATCH', '/api/v1/group-api/agents/7'],
            ['DELETE', '/api/v1/group-api/agents/7'],
            ['GET', '/api/v1/group-api/chats'],
            ['POST', '/api/v1/group-api/chats'],
            ['GET', '/api/v1/group-api/chats/7/status'],
            ['GET', '/api/v1/group-api/chats/7/messages'],
            ['POST', '/api/v1/group-api/chats/7/messages'],
            ['GET', '/api/v1/group-api/chats/7/transcript'],
            ['POST', '/api/v1/group-api/chats/7/approve'],
            ['POST', '/api/v1/group-api/chats/7/reject'],
            ['POST', '/api/v1/group-api/chats/7/answer'],
            ['POST', '/api/v1/group-api/media'],
            ['GET', '/api/v1/group-api/media/abc'],
        ] as [$method, $path]) {
            // 401 rather than 404: the route exists and its middleware
            // demands a credential. That is what distinguishes "mounted"
            // from "not mounted".
            expect($router->dispatch(Request::create($path, $method))->getStatusCode())
                ->toBe(401, "{$method} {$path} should route to a credentialed handler");
        }
    });

    it('matches every documented global endpoint', function (): void {
        $router = ScopedApiRouteGateSupport::router();

        foreach ([
            ['GET', '/api/v1/global-api/groups'],
            ['POST', '/api/v1/global-api/groups'],
            ['GET', '/api/v1/global-api/groups/3'],
            ['PATCH', '/api/v1/global-api/groups/3'],
            ['DELETE', '/api/v1/global-api/groups/3'],
            ['GET', '/api/v1/global-api/agents'],
            ['POST', '/api/v1/global-api/agents'],
            ['GET', '/api/v1/global-api/agents/7'],
            ['PATCH', '/api/v1/global-api/agents/7'],
            ['DELETE', '/api/v1/global-api/agents/7'],
            ['GET', '/api/v1/global-api/chats'],
            ['POST', '/api/v1/global-api/chats'],
            ['GET', '/api/v1/global-api/chats/7/status'],
            ['POST', '/api/v1/global-api/chats/7/approve'],
            ['POST', '/api/v1/global-api/media'],
            ['GET', '/api/v1/global-api/media/abc'],
        ] as [$method, $path]) {
            expect($router->dispatch(Request::create($path, $method))->getStatusCode())
                ->toBe(401, "{$method} {$path} should route to a credentialed handler");
        }
    });

    it('mounts the key-management endpoints behind the session', function (): void {
        $router = ScopedApiRouteGateSupport::router();

        foreach ([
            ['GET', '/api/v1/groups/3/api-keys'],
            ['POST', '/api/v1/groups/3/api-keys'],
            ['DELETE', '/api/v1/groups/3/api-keys/deadbeef'],
            ['GET', '/api/v1/global-api-keys'],
            ['POST', '/api/v1/global-api-keys'],
            ['DELETE', '/api/v1/global-api-keys/deadbeef'],
        ] as [$method, $path]) {
            // `AuthMiddleware` throws rather than returning, and `Kernel` is
            // what maps it to a 401 — so the throw *is* the proof the route
            // matched a session-gated handler.
            expect(fn() => $router->dispatch(Request::create($path, $method)))
                ->toThrow(UnauthenticatedException::class);
        }
    });

    it('never demands a CSRF token on a bearer surface', function (): void {
        // The bearer key is the only credential these surfaces accept: no
        // session cookie, no CSRF token, no fallback to one. A write without
        // a token therefore answers 401. A 403 would mean `CsrfMiddleware`
        // had been added to the stack — and an integrator would have no way
        // to satisfy it, because there is no session to take a token from.
        $router = ScopedApiRouteGateSupport::router();

        foreach ([
            ['POST', '/api/v1/group-api/agents'],
            ['POST', '/api/v1/group-api/chats'],
            ['POST', '/api/v1/group-api/chats/7/messages'],
            ['POST', '/api/v1/group-api/chats/7/approve'],
            ['POST', '/api/v1/group-api/media'],
            ['POST', '/api/v1/global-api/agents'],
            ['POST', '/api/v1/global-api/chats'],
            ['POST', '/api/v1/global-api/chats/7/answer'],
            ['POST', '/api/v1/global-api/media'],
            ['POST', '/api/v1/global-api/groups'],
        ] as [$method, $path]) {
            expect($router->dispatch(Request::create($path, $method))->getStatusCode())
                ->toBe(401, "{$method} {$path} must not answer 403 CSRF_TOKEN_MISSING");
        }
    });

    it('does not treat a literal action segment as a chat id', function (): void {
        // FastRoute matches in registration order, so a catch-all ahead of
        // the literal `status` route would answer it. Asserting the literal
        // still reaches the credentialed handler catches that reordering.
        $router = ScopedApiRouteGateSupport::router();

        foreach ([
            ['GET', 'status'],
            ['GET', 'messages'],
            ['POST', 'messages'],
            ['GET', 'transcript'],
            ['POST', 'approve'],
            ['POST', 'reject'],
            ['POST', 'answer'],
        ] as [$method, $literal]) {
            expect($router->dispatch(Request::create("/api/v1/group-api/chats/7/{$literal}", $method))->getStatusCode())
                ->toBe(401, "{$method} .../{$literal} must reach its own handler");
        }
    });
});

describe('the scope toggles', function (): void {
    it('answers 404 for the group surface when its flag is off', function (): void {
        $router = ScopedApiRouteGateSupport::router(['SPORA_GROUP_API' => '0', 'SPORA_GLOBAL_API' => '1']);

        foreach ([
            '/api/v1/group-api/agents',
            '/api/v1/group-api/chats',
            '/api/v1/group-api/group',
        ] as $path) {
            expect($router->dispatch(Request::create($path, 'GET'))->getStatusCode())
                ->toBe(404, "{$path} must be absent when the group scope is off");
        }
    });

    it('answers 404 for the global surface when its flag is off', function (): void {
        $router = ScopedApiRouteGateSupport::router(['SPORA_GROUP_API' => '1', 'SPORA_GLOBAL_API' => '0']);

        foreach ([
            '/api/v1/global-api/agents',
            '/api/v1/global-api/chats',
            '/api/v1/global-api/groups',
        ] as $path) {
            expect($router->dispatch(Request::create($path, 'GET'))->getStatusCode())
                ->toBe(404, "{$path} must be absent when the global scope is off");
        }
    });

    it('mounts one scope without mounting the other', function (): void {
        $groupOnly = ScopedApiRouteGateSupport::router(['SPORA_GROUP_API' => '1', 'SPORA_GLOBAL_API' => '0']);

        expect($groupOnly->dispatch(Request::create('/api/v1/group-api/agents', 'GET'))->getStatusCode())->toBe(401);
        expect($groupOnly->dispatch(Request::create('/api/v1/global-api/agents', 'GET'))->getStatusCode())->toBe(404);
    });

    it('does not mount the agent scope when only the scoped ones are on', function (): void {
        $router = ScopedApiRouteGateSupport::router([
            'SPORA_AGENT_API' => '0',
            'SPORA_GROUP_API' => '1',
            'SPORA_GLOBAL_API' => '1',
        ]);

        expect($router->dispatch(Request::create('/api/v1/agent-api/chats', 'GET'))->getStatusCode())->toBe(404);
        expect($router->dispatch(Request::create('/api/v1/group-api/chats', 'GET'))->getStatusCode())->toBe(401);
    });

    it('keeps listing and revoking mounted with the scopes off', function (): void {
        // An operator who enables a toggle gets a working section; one who
        // has not must still be able to list and revoke the keys they minted
        // earlier, so those routes are not gated.
        $router = ScopedApiRouteGateSupport::router(['SPORA_GROUP_API' => '0', 'SPORA_GLOBAL_API' => '0']);

        foreach ([
            ['GET', '/api/v1/groups/3/api-keys'],
            ['DELETE', '/api/v1/groups/3/api-keys/deadbeef'],
            ['GET', '/api/v1/global-api-keys'],
            ['DELETE', '/api/v1/global-api-keys/deadbeef'],
        ] as [$method, $path]) {
            expect(fn() => $router->dispatch(Request::create($path, $method)))
                ->toThrow(UnauthenticatedException::class);
        }
    });

    it('answers 405 for minting with the scopes off', function (): void {
        // Minting follows the scope's toggle — see `ScopedApiRouteDefinitions`.
        //
        // 405 rather than 404: the paths are still mounted for listing and
        // revoking, and a 404 would claim the endpoints do not exist when
        // two of their three methods do. Nothing is leaked by it either —
        // the 401 on `GET` already says the path is there.
        $router = ScopedApiRouteGateSupport::router(['SPORA_GROUP_API' => '0', 'SPORA_GLOBAL_API' => '0']);

        expect($router->dispatch(Request::create('/api/v1/groups/3/api-keys', 'POST'))->getStatusCode())
            ->toBe(405);
        expect($router->dispatch(Request::create('/api/v1/global-api-keys', 'POST'))->getStatusCode())
            ->toBe(405);
    });

    it('answers 404 in client-worker mode even with the scopes on', function (): void {
        // Nothing on the server executes a queued task there, so accepting
        // work would be a promise Spora cannot keep. The route is absent
        // rather than declining, so the surface stays unenumerable.
        $router = ScopedApiRouteGateSupport::router([
            'SPORA_GROUP_API' => '1',
            'SPORA_GLOBAL_API' => '1',
            'SPORA_WORKER_RUNTIME_MODE' => 'client',
        ]);

        expect($router->dispatch(Request::create('/api/v1/group-api/chats', 'GET'))->getStatusCode())->toBe(404);
        expect($router->dispatch(Request::create('/api/v1/global-api/chats', 'GET'))->getStatusCode())->toBe(404);
    });

    it('serves the surface after the flag is stored in the database', function (): void {
        ScopedApiRouteGateSupport::storeFlag('SPORA_GROUP_API', '1');

        // A fresh router, built from the same overlay `Kernel::handle()`
        // applies: no restart, no container rebuild.
        $config = new Config([]);
        $config->applySettingsOverlay(...ScopedApiRouteGateSupport::repository());

        expect($config->getBool('SPORA_GROUP_API'))->toBeTrue();

        $router = ScopedApiRouteGateSupport::routerFrom($config);

        expect($router->dispatch(Request::create('/api/v1/group-api/chats', 'GET'))->getStatusCode())->toBe(401);
    });
});

final class ScopedApiRouteGateSupport
{
    /**
     * @param array<string, string> $config
     */
    public static function router(array $config = ['SPORA_GROUP_API' => '1', 'SPORA_GLOBAL_API' => '1']): Router
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
        // flags reach the gate exactly as they do in production.
        return new Router($container, static function (MiddlewareRouteCollector $r) use ($config): void {
            \Spora\Core\ScopedApiRouteDefinitions::register($r, $config);
        });
    }

    /**
     * Persist a flag the way the admin settings endpoint does.
     *
     * `group_key` / `setting_key` / `value`, and group `framework` — every
     * non-mail setting lives there regardless of which UI section it is
     * shown under.
     */
    public static function storeFlag(string $key, string $value): void
    {
        \Illuminate\Database\Capsule\Manager::table('settings')->updateOrInsert(
            ['group_key' => 'framework', 'setting_key' => $key],
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
