<?php

declare(strict_types=1);

use Spora\Agents\SchemaValidator;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;
use Spora\Tools\SkillTool;

/**
 * Per-op `required[]` narrowing for SkillTool's `name` parameter.
 *
 * `list` takes no skill, so a schema that makes `name` required for every
 * operation makes `list` uncallable — the model has nothing to pass. This is
 * asserted through the schema, not through `SkillTool::execute()`, because
 * `execute()` is reached in tests with arguments the validator would never
 * have let through. A duplicate `#[ToolParameter(name: 'name')]` declaring
 * `required: true` twice did exactly that: the builder writes
 * `$properties[$param->name]` last-wins and only populates the `__required_when`
 * side channel for a list, so the parameter became unconditionally required and
 * every `skill(action: "list")` was rejected before dispatch.
 */
it('declares `name` as required for every operation except list', function (): void {
    $schema = ToolParameterSchemaBuilder::build(SkillTool::class);

    expect($schema['__required_when']['name'] ?? null)
        ->toBe(['read', 'files', 'activate']);

    foreach (['read', 'files', 'activate'] as $operation) {
        $filtered = OperationSchemaFilter::filter($schema, [$operation], 'action');
        expect($filtered['required'])->toContain('name');
    }

    $listOnly = OperationSchemaFilter::filter($schema, ['list'], 'action');
    expect($listOnly['required'])->not->toContain('name');
});

it('accepts every operation with the arguments it declares', function (): void {
    $schema = ToolParameterSchemaBuilder::build(SkillTool::class);

    $arguments = [
        'read' => ['action' => 'read', 'name' => 'demo'],
        'files' => ['action' => 'files', 'name' => 'demo'],
        // The regression: this is the call the schema used to reject.
        'list' => ['action' => 'list'],
        'activate' => ['action' => 'activate', 'name' => 'demo'],
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
