<?php

declare(strict_types=1);

namespace Spora\Events;

use Spora\Core\MiddlewareRouteCollector;

/**
 * Dispatched per request, after core and App routes are registered,
 * before the router is built. Listeners add routes via
 * {@see routes()}.
 *
 * @example
 * ```php
 * public function onRoutesRegistering(RoutesRegisteringEvent $event): void {
 *     $event->routes()->addRoute('GET', '/api/v1/things', [ThingsController::class, 'index']);
 * }
 * ```
 */
final class RoutesRegisteringEvent
{
    public function __construct(private readonly MiddlewareRouteCollector $routes) {}

    public function routes(): MiddlewareRouteCollector
    {
        return $this->routes;
    }
}
