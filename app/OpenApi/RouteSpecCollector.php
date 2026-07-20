<?php

declare(strict_types=1);

namespace Spora\OpenApi;

/**
 * Records every `addRoute(...)` call from `RouteDefinitions::register()` instead of building a
 * FastRoute dispatcher.
 *
 * `RouteToOpenApi` replays `RouteDefinitions::register()` against an instance of this collector
 * to obtain the canonical route list with no DB / no kernel boot. Paths, methods, and
 * middleware originate here; they never drift from `RouteDefinitions` because we read
 * directly from the same source.
 */
final class RouteSpecCollector
{
    /** @var list<array{method:string, route:string, handler:array{0:string,1:string}, middleware:list<string>}> */
    private array $routes = [];

    /**
     * Recorded route table; same shape `RouteDefinitions::register()` wrote.
     *
     * @return list<array{method:string, route:string, handler:array{0:string,1:string}, middleware:list<string>}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Captures a route registration. Mirrors the
     * {@see \Spora\Core\MiddlewareRouteCollector::addRoute()} signature without inheriting
     * (that class is `final`), so a build-time invocation of `RouteDefinitions::register()`
     * never touches FastRoute.
     *
     * @param array{0: string, 1: string} $handler
     * @param list<string>                 $middleware
     */
    public function addRoute(string $httpMethod, string $route, array $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($httpMethod),
            'route' => $route,
            'handler' => [$handler[0], $handler[1]],
            'middleware' => $middleware,
        ];
    }
}
