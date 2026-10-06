<?php

declare(strict_types=1);

use Spora\Core\RouteDefinitions;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\SearchController;
use Spora\OpenApi\RouteSpecCollector;

/**
 * `GET /api/v1/search` lives in `registerSearchRoutes()` rather than
 * alongside the skill routes, so that a move cannot quietly drop or duplicate
 * it. Route registration is what the ⌘K palette depends on, and nothing else
 * in the suite reads this entry back out of the collector.
 *
 * @return list<array<string, mixed>>
 */
function searchRouteRows(): array
{
    $collector = new RouteSpecCollector();
    RouteDefinitions::register($collector);

    return array_values(array_filter(
        $collector->routes(),
        static fn(array $row): bool => $row['route'] === '/api/v1/search',
    ));
}

it('registers GET /api/v1/search exactly once', function (): void {
    expect(searchRouteRows())->toHaveCount(1);
});

it('serves the search endpoint from the palette controller behind AuthMiddleware alone', function (): void {
    // A GET the palette fires on focus, so no CSRF gate — matching the other
    // plain reads. Adding CsrfMiddleware here would make the palette 403 on
    // focus, which is not a failure anyone would read as a CSRF problem.
    $row = searchRouteRows()[0];

    expect($row['method'])->toBe('GET')
        ->and($row['handler'])->toBe([SearchController::class, 'index'])
        ->and($row['middleware'])->toBe([AuthMiddleware::class])
        ->and($row['middleware'])->not->toContain(CsrfMiddleware::class);
});
