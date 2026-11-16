<?php

declare(strict_types=1);

namespace Tests\Feature;

use Spora\Http\EffortOpenApi;
use Spora\OpenApi\RouteToOpenApi;

/**
 * The `effort` field is documented once and referenced everywhere.
 *
 * The paragraph names the chain that decides the level and the endpoints
 * that list the levels, so it has to change when either changes — and it
 * is the same paragraph on every surface that accepts the field. A copy
 * per surface drifts, and the drift is invisible until a client reads two
 * of them side by side.
 */
describe('the effort field in the OpenAPI spec', function (): void {
    it('carries the one description on every surface that accepts it', function (): void {
        $described = effortSpecProperties();

        expect($described)->not->toBeEmpty();

        foreach ($described as $route => $property) {
            expect($property['description'] ?? null)->toBe(
                EffortOpenApi::FIELD_DESCRIPTION,
                $route . ' must carry the shared description',
            );
        }
    });

    it('documents the field on every surface that accepts it', function (): void {
        $routes = array_keys(effortSpecProperties());

        foreach ([
            'POST /api/v1/tasks',
            'POST /api/v1/tasks/{taskId}/continue',
            'POST /api/v1/agent-api/chats',
            'POST /api/v1/agent-api/chats/{chatId}/messages',
            'POST /api/v1/agent-api/transcribe-and-send',
            'POST /api/v1/group-api/chats',
            'POST /api/v1/global-api/chats',
        ] as $route) {
            expect($routes)->toContain($route);
        }
    });

    it('no longer pins the levels to a fixed enum', function (): void {
        foreach (effortSpecProperties() as $route => $property) {
            expect($property)->not->toHaveKey(
                'enum',
                message: $route . ' must not list a fixed set of levels: the configuration decides which ones exist',
            );
        }
    });
});

/**
 * The `effort` property of every request body that accepts one.
 *
 * @return array<string, array<string, mixed>> keyed by "METHOD path"
 */
function effortSpecProperties(): array
{
    $json = json_decode((string) json_encode((new RouteToOpenApi())->build()), true);

    $described = [];

    foreach ($json['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $property = $operation['requestBody']['content']['application/json']['schema']['properties']['effort'] ?? null;

            if (is_array($property)) {
                $described[strtoupper((string) $method) . ' ' . $path] = $property;
            }
        }
    }

    return $described;
}
