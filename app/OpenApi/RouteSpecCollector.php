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
 *
 * Mirrors `Spora\Core\MiddlewareRouteCollector::addRoute($method, $route, $handler, $middleware)`
 * — not a subclass because `MiddlewareRouteCollector` is `final`. The signature is the contract
 * that matters; the FastRoute parent state isn't relevant here.
 */
final class RouteSpecCollector
{
    /** @var list<array{method:string, route:string, handler:array{0:string,1:string}, middleware:list<string>}> */
    private array $routes = [];

    public function routes(): array
    {
        return $this->routes;
    }

    public function addRoute(string $httpMethod, string $route, array $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($httpMethod),
            'route' => $route,
            'handler' => [$handler[0], $handler[1]],
            'middleware' => array_values($middleware),
        ];
    }
}
