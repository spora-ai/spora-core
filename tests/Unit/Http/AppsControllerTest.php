<?php

declare(strict_types=1);

use Spora\Apps\AppRegistry;
use Spora\Http\AppsController;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\PluginLoader;
use Spora\Security\CsrfTokenService;
use Tests\Fixtures\StubSampleApp;
use Tests\Fixtures\StubVueApp;
use Tests\Fixtures\StubVueAppEmpty;

function makeAppsController(?AppRegistry $registry = null, ?PluginLoader $loader = null): array
{
    $authService = bootAuthLayer();
    $registry ??= new AppRegistry();
    $controller = new AppsController($registry, $loader);
    $authMiddleware = new AuthMiddleware($authService);
    $csrfMiddleware = new CsrfMiddleware(new CsrfTokenService());

    return [$controller, $authMiddleware, $csrfMiddleware];
}

describe('AppsController', function (): void {
    it('rejects anonymous requests with 401', function (): void {
        clearSession();
        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController();

        $request = jsonRequest('GET', '/api/v1/apps');
        expect(fn() => callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    it('returns the registered apps for an authenticated user', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('apps@example.com', 'ValidPass1!', 'Apps');
        simulateLoggedInSession($userId, 'apps@example.com');

        $registry = new AppRegistry();
        $registry->register(StubSampleApp::class);

        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController($registry);

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body)->toHaveKey('data');
        expect($body['data']['apps'])->toHaveCount(1);
        expect($body['data']['apps'][0])->toMatchArray([
            'name' => 'sample',
            'displayName' => 'Sample',
            'icon' => 'puzzle',
            'route' => '/apps/sample',
        ]);
    });

    it('returns an empty apps array when nothing is registered', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('empty@example.com', 'ValidPass1!', 'Empty');
        simulateLoggedInSession($userId, 'empty@example.com');

        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController();

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['apps'])->toBe([]);
    });

    it('emits frontendEntry when an app implements VueAppInterface', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('vue@example.com', 'ValidPass1!', 'Vue');
        simulateLoggedInSession($userId, 'vue@example.com');

        $registry = new AppRegistry();
        $registry->register(StubVueApp::class);

        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController($registry);

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['apps'][0])->toHaveKey('frontendEntry');
        expect($body['data']['apps'][0]['frontendEntry'])->toBe('main.js');
    });

    it('omits frontendEntry when a VueAppInterface returns an empty entry', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('vue-empty@example.com', 'ValidPass1!', 'VueEmpty');
        simulateLoggedInSession($userId, 'vue-empty@example.com');

        $registry = new AppRegistry();
        $registry->register(StubVueAppEmpty::class);

        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController($registry);

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['apps'][0])->not->toHaveKey('frontendEntry');
    });

    it('does not emit frontendEntry when no PluginLoader is injected', function (): void {
        // A plain AppInterface (no VueAppInterface) and no PluginLoader
        // means the controller has no source for frontendEntry. The payload
        // must still serialise without the key — never null, never empty.
        $authService = bootAuthLayer();
        $userId = $authService->register('noentry@example.com', 'ValidPass1!', 'NoEntry');
        simulateLoggedInSession($userId, 'noentry@example.com');

        $registry = new AppRegistry();
        $registry->register(StubSampleApp::class);

        // Explicitly pass null for the loader.
        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController($registry, null);

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['apps'][0])->not->toHaveKey('frontendEntry');
    });

    it('emits the plugin slug for plugin-owned apps', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('slug@example.com', 'ValidPass1!', 'Slug');
        simulateLoggedInSession($userId, 'slug@example.com');

        $registry = new AppRegistry();
        $registry->register(StubVueApp::class);

        $loader = new PluginLoader(
            [BASE_PATH . '/tests/Fixtures/plugins_with_app'],
            null,
        );
        $loader->boot();

        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController($registry, $loader);

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['apps'][0])->toHaveKey('slug');
        expect($body['data']['apps'][0]['slug'])->toBe('app-plugin');
    });

    it('omits the slug for core-owned apps not registered by any plugin', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('noslug@example.com', 'ValidPass1!', 'NoSlug');
        simulateLoggedInSession($userId, 'noslug@example.com');

        $registry = new AppRegistry();
        $registry->register(StubSampleApp::class);

        $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_tools'], null);
        $loader->boot();

        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController($registry, $loader);

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['apps'][0])->not->toHaveKey('slug');
    });
});
