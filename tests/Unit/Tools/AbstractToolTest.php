<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\ToolInterface;

it('a concrete subclass is instantiable and produces a valid schema via the composed traits', function (): void {
    // Realistic subclass exercises both composed traits (HasOperations +
    // HasParameterSchema) — covered structurally by the other tests below.
    $tool = new AbstractToolTestFixture();
    expect($tool)->toBeInstanceOf(ToolInterface::class);
    expect($tool->getParametersSchema())->toBeArray()
        ->and($tool->getParametersSchema()['type'])->toBe('object');
});

it('provides getParametersSchema() via the composed trait', function (): void {
    $tool = new AbstractToolTestFixture();
    $schema = $tool->getParametersSchema();

    expect(array_keys($schema['properties']))->toBe(['action', 'q'])
        ->and($schema['properties']['action']['enum'])->toBe(['run', 'stop'])
        ->and($schema['required'])->toBe(['action', 'q']);
});

it('provides operation dispatch via the composed HasOperations trait', function (): void {
    $tool = new AbstractToolTestFixture();

    expect($tool->getOperationName(['action' => 'stop']))->toBe('stop')
        ->and($tool->getOperationName([]))->toBe('run');  // fallback to first declared op
});

it('lets subclasses declare arbitrary constructors (DI signatures preserved)', function (): void {
    $tool = new AbstractToolTestWithDi(prefix: 'hello');

    expect($tool->execute([], 1)->content)->toBe('hello: ok');
});
