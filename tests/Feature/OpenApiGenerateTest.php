<?php

declare(strict_types=1);

use OpenApi\Annotations\OpenApi;
use Spora\OpenApi\RouteSpecCollector;
use Spora\OpenApi\RouteToOpenApi;

/**
 * Mirrors `RouteDefinitions::register()` but captures the rows into a plain list, so the
 * test can assert the OpenApi document covers every route.
 */
function openApiTestCollectRoutes(): array
{
    $collector = new RouteSpecCollector();
    Spora\Core\RouteDefinitions::register($collector);

    return $collector->routes();
}

function openApiTestSpec(): OpenApi
{
    return (new RouteToOpenApi())->build();
}

function openApiTestFindOperation(OpenApi $spec, string $method, string $path): ?object
{
    $method = strtolower($method);
    $pathItem = $spec->paths[$path] ?? null;

    return $pathItem ? ($pathItem->{$method} ?? null) : null;
}

describe('OpenAPI specification generation', function (): void {
    it('produces an OpenAPI document with the expected root metadata', function (): void {
        $spec = openApiTestSpec();

        expect($spec->openapi)->toStartWith('3.');
        expect($spec->info->title)->toBe('Spora API');
        expect($spec->info->version)->not->toBeEmpty();
        expect($spec->servers)->toHaveCount(1);
        expect($spec->servers[0]->url)->toBe('/');
    });

    it('declares cookieAuth and csrfToken security schemes', function (): void {
        $spec = openApiTestSpec();

        expect($spec->components->securitySchemes)->toHaveCount(2);

        $names = array_map(static fn($s) => $s->securityScheme, $spec->components->securitySchemes);
        expect($names)->toContain('cookieAuth');
        expect($names)->toContain('csrfToken');
    });

    it('covers every route registered in RouteDefinitions', function (): void {
        $spec = openApiTestSpec();
        $missing = [];

        foreach (openApiTestCollectRoutes() as $entry) {
            $op = openApiTestFindOperation(
                $spec,
                $entry['method'],
                preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):[^\}]+\}/', '{$1}', $entry['route']) ?? $entry['route'],
            );
            if ($op === null) {
                $missing[] = sprintf('%s %s', $entry['method'], $entry['route']);
            }
        }

        expect($missing)->toBe([], sprintf('Spec is missing routes: %s', implode(', ', $missing)));
    });

    it('derives security from AuthMiddleware', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'GET', '/api/v1/agents');
        expect($op)->not->toBeNull();
        expect(array_column($op->security, 'cookieAuth'))->not->toBe([]);
    });

    it('derives csrfToken security from CsrfMiddleware', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'POST', '/api/v1/agents');
        expect($op)->not->toBeNull();
        expect(array_column($op->security, 'csrfToken'))->not->toBe([]);
    });

    it('leaves security empty for public (login) routes', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'POST', '/api/v1/auth/login');
        expect($op)->not->toBeNull();
        expect($op->security)->toBe([]);
    });

    it('tags operations by the first /api/v1/ segment', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'GET', '/api/v1/agents');
        expect($op->tags)->toContain('Agents');
    });

    it('drops FastRoute regex constraints on path params', function (): void {
        $spec = openApiTestSpec();

        if (!isset($spec->paths['/api/v1/agent-templates/{id}'])) {
            // The route exists in RouteDefinitions with the {id:.+} form; the adapter
            // should normalise it. If absent, the test target is wrong — fail loudly.
            expect($spec->paths)->toHaveKey('/api/v1/agent-templates/{id}');
        }

        $op = $spec->paths['/api/v1/agent-templates/{id}']->get ?? null;
        expect($op)->not->toBeNull();

        $paramNames = array_map(static fn($p) => $p->name, $op->parameters);
        expect($paramNames)->toContain('id');
    });
});
