<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use RuntimeException;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Exceptions\ToolHttpErrorException;
use Spora\Tools\Exceptions\ToolOperationMissingException;
use Spora\Tools\Exceptions\ToolParameterSchemaException;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

it('ToolHttpErrorException extends RuntimeException', function (): void {
    $ex = new ToolHttpErrorException('boom');
    expect($ex)->toBeInstanceOf(RuntimeException::class)
        ->and($ex->getMessage())->toBe('boom');
});

it('ToolOperationMissingException extends RuntimeException', function (): void {
    $ex = new ToolOperationMissingException('missing');
    expect($ex)->toBeInstanceOf(RuntimeException::class)
        ->and($ex->getMessage())->toBe('missing');
});

it('ToolParameterSchemaException extends RuntimeException', function (): void {
    $ex = new ToolParameterSchemaException('bad schema');
    expect($ex)->toBeInstanceOf(RuntimeException::class)
        ->and($ex->getMessage())->toBe('bad schema');
});

it('HasOperations trait throws ToolOperationMissingException when no #[ToolOperation] is declared', function (): void {
    $instance = new HasOperationsFixtureWithoutOps();
    expect(fn() => $instance->getOperationName([]))
        ->toThrow(
            ToolOperationMissingException::class,
            'getOperationName() called on a tool that has no #[ToolOperation] attributes. '
            . 'Either add #[ToolOperation] declarations or do not use the HasOperations trait.',
        );
});

it('ToolParameterSchemaBuilder throws ToolParameterSchemaException on discriminator collision', function (): void {
    $cls = new
        #[ToolOperation(name: 'list_events', description: 'List events')]
        #[ToolOperation(name: 'create_event', description: 'Create event')]
        #[ToolParameter(name: 'action', type: 'string', description: 'collides with discriminator', required: true)] class {};

    expect(fn() => ToolParameterSchemaBuilder::build($cls))
        ->toThrow(ToolParameterSchemaException::class);
});
