<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\SearchController;
use Spora\OpenApi\RouteSpecCollector;

/**
 * Routes for the ⌘K palette's server-backed search surface.
 *
 * Lives outside {@see RouteDefinitions} to keep that class under the
 * 20-method Sonar brain-overload threshold, the same reason
 * {@see SpeechRouteDefinitions} does.
 *
 * Endpoint (the LLM mirror):
 *
 *   GET    /api/v1/search — cross-provider search over every registered
 *                           provider (auth only). Before this endpoint the
 *                           palette filtered Pinia stores directly, which
 *                           left anything served by a plugin unsearchable.
 */
final class SearchRouteDefinitions
{
    public static function register(MiddlewareRouteCollector | RouteSpecCollector $r): void
    {
        // Authenticated but not CSRF-gated: a GET the palette fires on focus,
        // exactly like the user-profile and SSE reads.
        $r->addRoute('GET', '/api/v1/search', [SearchController::class, 'index'], [AuthMiddleware::class]);
    }
}
