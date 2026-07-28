<?php

declare(strict_types=1);

use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

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

it('narrows required[] to properties whose per-op binding intersects the allowed set', function (): void {
    // Schema built by the builder carries __required_when as a top-level
    // side channel; the filter reads it, narrows required[], and strips it.
    $schema = [
        'type'             => 'object',
        'properties'       => [
            'action'   => ['type' => 'string',  'enum' => ['now', 'format']],
            'epoch'    => ['type' => 'integer'],
            'timezone' => ['type' => 'string'],
            'name'     => ['type' => 'string'],
        ],
        'required'         => ['action', 'epoch', 'timezone', 'name'],
        ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY => [
            'epoch'    => ['format'],
            'timezone' => ['format'],
        ],
    ];

    expect(OperationSchemaFilter::filter($schema, ['now'], 'action')['required'])
        ->toBe(['action', 'name']);
    expect(OperationSchemaFilter::filter($schema, ['format'], 'action')['required'])
        ->toBe(['action', 'epoch', 'timezone', 'name']);
    expect(OperationSchemaFilter::filter($schema, ['now', 'format'], 'action')['required'])
        ->toBe(['action', 'epoch', 'timezone', 'name']);
});

it('strips the __required_when side channel before the schema reaches the LLM', function (): void {
    $schema = [
        'type'             => 'object',
        'properties'       => [
            'action' => ['type' => 'string', 'enum' => ['now']],
            'epoch'  => ['type' => 'integer'],
        ],
        'required'         => ['action'],
        ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY => ['epoch' => ['format']],
    ];

    $filtered = OperationSchemaFilter::filter($schema, ['now'], 'action');

    expect($filtered)->not->toHaveKey(ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY);
});

it('drops per-op-bound properties from the LLM-facing schema when no allowed op intersects', function (): void {
    // Mirrors AgentTool: `agent` is bound to write_agent_configuration only.
    // An agent that can only read_notes should not see (or emit) `agent: []`.
    $schema = [
        'type'             => 'object',
        'properties'       => [
            'action' => ['type' => 'string', 'enum' => ['read_notes', 'write_notes', 'write_agent_configuration']],
            'agent'  => ['type' => 'object', 'description' => 'For write_agent_configuration only.'],
            'content' => ['type' => 'string'],
            'mode'    => ['type' => 'string'],
        ],
        'required'         => ['action'],
        ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY => [
            'agent'   => ['write_agent_configuration'],
            'content' => ['write_notes'],
        ],
    ];

    $filtered = OperationSchemaFilter::filter($schema, ['read_notes'], 'action');

    expect($filtered['properties'])->not->toHaveKey('agent')
        ->and($filtered['properties'])->not->toHaveKey('content')
        ->and($filtered['properties'])->toHaveKey('mode');  // unbound, shared, stays.
});

it('keeps per-op-bound properties when an allowed op intersects', function (): void {
    $schema = [
        'type'             => 'object',
        'properties'       => [
            'action' => ['type' => 'string', 'enum' => ['write_agent_configuration']],
            'agent'  => ['type' => 'object'],
        ],
        'required'         => ['action'],
        ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY => [
            'agent' => ['write_agent_configuration'],
        ],
    ];

    $filtered = OperationSchemaFilter::filter($schema, ['write_agent_configuration'], 'action');

    expect($filtered['properties'])->toHaveKey('agent');
});

it('drops per-op-bound properties at runtime in filterForOperation', function (): void {
    $schema = [
        'type'             => 'object',
        'properties'       => [
            'action'  => ['type' => 'string', 'enum' => ['read_notes', 'write_notes']],
            'content' => ['type' => 'string'],
            'mode'    => ['type' => 'string'],
        ],
        'required'         => ['action'],
        ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY => [
            'content' => ['write_notes'],
        ],
    ];

    $filtered = OperationSchemaFilter::filterForOperation($schema, 'read_notes');

    expect($filtered['properties'])->not->toHaveKey('content')
        ->and($filtered['properties'])->toHaveKey('mode');
});
