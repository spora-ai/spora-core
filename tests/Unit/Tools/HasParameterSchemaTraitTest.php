<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

it('returns the builder output via getParametersSchema()', function (): void {
    $tool = new HasParameterSchemaTraitTestSimpleTool();
    $schema = $tool->getParametersSchema();

    expect(array_keys($schema['properties']))->toBe(['x'])
        ->and($schema['properties']['x']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['x']);
});

it('lets a class override the trait method without breaking', function (): void {
    // Trait methods are not final; an overriding class wins resolution.
    $tool = new HasParameterSchemaTraitTestOverridingTool();

    expect($tool->getParametersSchema())->toBe(['custom' => true]);
});

it('reads attributes from $this, not a static cache', function (): void {
    // Two instances of the same class should produce equal results, and instances
    // of two different classes that both `use HasParameterSchema` must not bleed
    // attributes across each other.
    $a = new HasParameterSchemaTraitTestSimpleTool();
    $b = new HasParameterSchemaTraitTestSecondTool();

    $schemaA = $a->getParametersSchema();
    $schemaB = $b->getParametersSchema();

    expect(array_keys($schemaA['properties']))->toBe(['x'])
        ->and(array_keys($schemaB['properties']))->toBe(['y']);
});
