<?php

declare(strict_types=1);

use Spora\Agents\SchemaValidator;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;
use Spora\Tools\SkillTool;

/**
 * `SkillTool`'s `name` argument, asserted through the schema rather than
 * through `SkillTool::execute()`.
 *
 * `execute()` is reached in tests with arguments the validator would never have
 * let through, so a schema bug is invisible there. The bug these pin: a
 * duplicate `#[ToolParameter(name: 'name')]` declaring `required: true` twice
 * did exactly that — the builder writes `$properties[$param->name]` last-wins
 * and only populates the `__required_when` side channel for a list, so the
 * parameter became unconditionally required and the `list` operation was
 * rejected before dispatch.
 *
 * The tool is read-only now, so every operation that exists takes a slug and
 * `required: true` is unconditionally right — the per-operation narrowing the
 * two-operation version needed is gone with the operations.
 */
it('makes `name` required for every operation, with no per-op narrowing left', function (): void {
    $schema = ToolParameterSchemaBuilder::build(SkillTool::class);

    // No `__required_when` entry at all: both operations need the argument, so
    // the per-op channel would carry the same list twice and read as if it
    // meant something.
    expect($schema['__required_when']['name'] ?? null)->toBeNull();

    foreach (['read', 'files'] as $operation) {
        $filtered = OperationSchemaFilter::filter($schema, [$operation], 'action');
        expect($filtered['required'])->toContain('name');
    }
});

it('accepts every operation with the arguments it declares', function (): void {
    $schema = ToolParameterSchemaBuilder::build(SkillTool::class);

    $arguments = [
        'read'  => ['action' => 'read', 'name' => 'demo'],
        'files' => ['action' => 'files', 'name' => 'demo'],
    ];

    foreach ($arguments as $operation => $args) {
        $filtered = OperationSchemaFilter::filter($schema, [$operation], 'action');

        // A block-bodied closure, not `fn() => …`: `validate()` returns void, and
        // an arrow function wrapping a void call trips PHPStan's `return.void`.
        expect(function () use ($args, $filtered, $operation): void {
            SchemaValidator::validate($args, $filtered, $operation);
        })->not->toThrow(InvalidArgumentException::class);
    }
});

it('rejects an operation that omits an argument its schema marks required', function (): void {
    $schema = ToolParameterSchemaBuilder::build(SkillTool::class);

    // A permissive schema would let a skill read through with no slug, which is
    // the same class of bug in the other direction: the guard has to bite too.
    $filtered = OperationSchemaFilter::filter($schema, ['read'], 'action');

    expect(function () use ($filtered): void {
        SchemaValidator::validate(['action' => 'read'], $filtered, 'read');
    })->toThrow(InvalidArgumentException::class, "Required argument 'name'");
});
