<?php

declare(strict_types=1);

use Spora\Apps\AppRegistry;
use Spora\Http\AppsController;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Security\CsrfTokenService;
use Tests\Fixtures\StubMemoriesApp;

function makeAppsController(?AppRegistry $registry = null): array
{
    $authService = bootAuthLayer();
    $registry ??= new AppRegistry();
    $controller = new AppsController($registry);
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
        $registry->register(StubMemoriesApp::class);

        [$controller, $authMiddleware, $csrfMiddleware] = makeAppsController($registry);

        $request = jsonRequest('GET', '/api/v1/apps');
        $response = callController($controller, 'index', $request, [$authMiddleware, $csrfMiddleware]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body)->toHaveKey('data');
        expect($body['data']['apps'])->toHaveCount(1);
        expect($body['data']['apps'][0])->toMatchArray([
            'name' => 'memories',
            'displayName' => 'Memories',
            'icon' => 'brain',
            'route' => '/apps/memories',
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
});
