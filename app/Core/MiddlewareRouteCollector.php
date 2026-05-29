<?php

declare(strict_types=1);

namespace Spora\Core;

use FastRoute\RouteCollector;

/**
 * Extended RouteCollector that captures a 4th middleware argument per route.
 */
final class MiddlewareRouteCollector extends RouteCollector
{
    public function __construct(
        \FastRoute\RouteParser $parser,
        \FastRoute\DataGenerator $dataGenerator,
    ) {
        parent::__construct($parser, $dataGenerator);
    }

    public function addRoute($httpMethod, $route, $handler, array $middleware = []): void
    {
        parent::addRoute($httpMethod, $route, ['handler' => $handler, 'middleware' => $middleware]);
    }
}
