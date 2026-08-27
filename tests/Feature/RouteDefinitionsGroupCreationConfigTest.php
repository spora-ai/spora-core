<?php

declare(strict_types=1);

use OpenApi\Annotations\OpenApi;
use Spora\OpenApi\RouteSpecCollector;
use Spora\OpenApi\RouteToOpenApi;

/**
 * Captures the routes RouteDefinitions would register with the supplied
 * config snapshot. Mirrors `OpenApiGenerateTest::openApiTestCollectRoutes`
 * but threads the `$config` parameter through.
 *
 * @param array<string, mixed> $config
 * @return list<array<string, mixed>>
 */
function routeRegCollectRoutes(array $config): array
{
    $collector = new RouteSpecCollector();
    Spora\Core\RouteDefinitions::register($collector, $config);

    return $collector->routes();
}

/**
 * Build the OpenAPI spec with an explicit config snapshot.
 *
 * @param array<string, mixed> $config
 */
function routeRegSpec(array $config): OpenApi
{
    return (new RouteToOpenApi())->build($config);
}

/**
 * Locate the middleware list for a (method, path) tuple.
 *
 * @param list<array<string, mixed>> $routes
 * @return list<string>|null
 */
function routeRegMiddleware(array $routes, string $method, string $path): ?array
{
    foreach ($routes as $row) {
        if ($row['method'] === strtoupper($method) && $row['route'] === $path) {
            return $row['middleware'];
        }
    }
    return null;
}

describe('POST /api/v1/groups — allow_group_creation config flag', function (): void {
    it('registers AuthMiddleware only (no AdminMiddleware) when allow_group_creation=true', function (): void {
        $routes = routeRegCollectRoutes(['allow_group_creation' => true]);

        $middleware = routeRegMiddleware($routes, 'POST', '/api/v1/groups');
        expect($middleware)->not->toBeNull();
        expect($middleware)->toContain(Spora\Http\Middleware\AuthMiddleware::class);
        expect($middleware)->toContain(Spora\Http\Middleware\CsrfMiddleware::class);
        expect($middleware)->not->toContain(Spora\Http\Middleware\AdminMiddleware::class);
    });

    it('registers AdminMiddleware when allow_group_creation=false', function (): void {
        $routes = routeRegCollectRoutes(['allow_group_creation' => false]);

        $middleware = routeRegMiddleware($routes, 'POST', '/api/v1/groups');
        expect($middleware)->not->toBeNull();
        expect($middleware)->toContain(Spora\Http\Middleware\AdminMiddleware::class);
    });

    it('defaults to the open variant (allow_group_creation=true) when config is empty', function (): void {
        $routes = routeRegCollectRoutes([]);

        $middleware = routeRegMiddleware($routes, 'POST', '/api/v1/groups');
        expect($middleware)->not->toBeNull();
        expect($middleware)->not->toContain(Spora\Http\Middleware\AdminMiddleware::class);
    });

    it('leaves PATCH and DELETE on AdminMiddleware regardless of the flag', function (): void {
        foreach ([true, false] as $value) {
            $routes = routeRegCollectRoutes(['allow_group_creation' => $value]);

            $patch = routeRegMiddleware($routes, 'PATCH', '/api/v1/groups/{id}');
            expect($patch)->toContain(Spora\Http\Middleware\AdminMiddleware::class);

            $delete = routeRegMiddleware($routes, 'DELETE', '/api/v1/groups/{id}');
            expect($delete)->toContain(Spora\Http\Middleware\AdminMiddleware::class);
        }
    });

    it('emits the same security shape in the OpenAPI doc regardless of the flag (auth+csrf are always there)', function (): void {
        $openSpec = routeRegSpec(['allow_group_creation' => true]);
        $strictSpec = routeRegSpec(['allow_group_creation' => false]);

        foreach ([true, false] as $value) {
            $spec = $value ? $openSpec : $strictSpec;
            $op = $spec->paths['/api/v1/groups']->post ?? null;
            expect($op)->not->toBeNull();
            expect(array_column($op->security, 'cookieAuth'))->not->toBe([]);
            expect(array_column($op->security, 'csrfToken'))->not->toBe([]);
        }
    });
});

describe('GET /api/v1/llm-configs/global — read-side middleware (regression for stale-cache-group bug)', function (): void {
    it('registers AuthMiddleware + CsrfMiddleware only (no AdminMiddleware)', function (): void {
        // Non-admin users (incl. plain group members) need to read global
        // LLM configs to choose a default at agent-creation time. The
        // write paths (POST /llm-configs, /llm-configs/{id}/set-default)
        // still admin-gate other behaviour downstream.
        $routes = routeRegCollectRoutes([]);

        $middleware = routeRegMiddleware($routes, 'GET', '/api/v1/llm-configs/global');
        expect($middleware)->not->toBeNull();
        expect($middleware)->toContain(Spora\Http\Middleware\AuthMiddleware::class);
        expect($middleware)->toContain(Spora\Http\Middleware\CsrfMiddleware::class);
        expect($middleware)->not->toContain(Spora\Http\Middleware\AdminMiddleware::class);
    });
});
