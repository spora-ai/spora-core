<?php

declare(strict_types=1);

namespace Spora\Core;

use FastRoute\RouteCollector;

/**
 * Extended RouteCollector that captures a 4th middleware argument per route.
 * Middleware arrays are stored in $routeMiddleware keyed by route pattern.
 */
final class MiddlewareRouteCollector extends RouteCollector
{
    /** @var array<string, array<int, class-string>> Pattern => middleware list */
    private static array $routeMiddleware = [];

    public function addRoute($httpMethod, $route, $handler, array $middleware = []): void
    {
        parent::addRoute($httpMethod, $route, $handler);

        if ($middleware !== []) {
            $fullPattern = $this->currentGroupPrefix . $route;
            self::$routeMiddleware[$fullPattern] = $middleware;
        }
    }

    /**
     * Returns all captured middleware indexed by route pattern.
     *
     * @return array<string, array<int, class-string>>
     */
    public static function getRouteMiddleware(): array
    {
        return self::$routeMiddleware;
    }

    /**
     * Clears the middleware map. Useful between test runs.
     */
    public static function clear(): void
    {
        self::$routeMiddleware = [];
    }
}
