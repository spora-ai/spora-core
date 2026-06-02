<?php

declare(strict_types=1);

use Spora\Tools\Schema\OperationSchemaFilter;

it('filters the action enum to allowed operations', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['create', 'update', 'delete'],
            ],
            'name' => ['type' => 'string'],
        ],
        'required' => ['action'],
    ];

    $filtered = OperationSchemaFilter::filter($schema, ['create', 'update'], 'action');

    expect($filtered['properties']['action']['enum'])->toBe(['create', 'update'])
        ->and($filtered['properties']['name'])->toBe(['type' => 'string']);  // unrelated props preserved
});

it('respects a custom discriminator key', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'op' => [
                'type' => 'string',
                'enum' => ['search', 'top_news'],
            ],
            'q' => ['type' => 'string'],
        ],
        'required' => ['op'],
    ];

    $filtered = OperationSchemaFilter::filter($schema, ['search'], 'op');

    expect($filtered['properties']['op']['enum'])->toBe(['search'])
        // The hardcoded 'action' field would have been a no-op here — the bug fix
        // is that the filter now correctly narrows the 'op' enum instead.
        ->and($filtered['properties']['op']['enum'])->not->toContain('top_news');
});

it('leaves schemas without the discriminator property untouched', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'A URL'],
        ],
        'required' => ['url'],
    ];

    $filtered = OperationSchemaFilter::filter($schema, [], 'action');

    expect($filtered['properties'])->toBe(['url' => ['type' => 'string', 'description' => 'A URL']]);
});

it('returns an empty enum when allowedOps is empty', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['a', 'b'],
            ],
        ],
        'required' => ['action'],
    ];

    $filtered = OperationSchemaFilter::filter($schema, [], 'action');

    expect($filtered['properties']['action']['enum'])->toBe([]);
});

it('coerces stdClass properties to a typed array for filtering and back to stdClass when empty', function (): void {
    $schema = [
        'type' => 'object',
        'properties' => new stdClass(),
        'required' => [],
    ];

    $filtered = OperationSchemaFilter::filter($schema, ['x'], 'action');

    expect($filtered['properties'])->toBeInstanceOf(stdClass::class);
});
