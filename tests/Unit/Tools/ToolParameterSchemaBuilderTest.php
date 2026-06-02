<?php

declare(strict_types=1);

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

it('returns an empty stdClass properties object when there are no attributes', function (): void {
    $cls = new class {};
    $schema = ToolParameterSchemaBuilder::build($cls);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toBeInstanceOf(stdClass::class)
        ->and($schema['required'])->toBe([]);
});

it('preserves #[ToolParameter] declaration order', function (): void {
    $cls = new
        #[ToolParameter(name: 'zebra', type: 'string', description: 'Last alphabetically', required: false)]
        #[ToolParameter(name: 'alpha', type: 'string', description: 'First alphabetically', required: false)]
        #[ToolParameter(name: 'mango', type: 'string', description: 'Middle alphabetically', required: false)]
    class {};

    $schema = ToolParameterSchemaBuilder::build($cls);

    expect(array_keys($schema['properties']))->toBe(['zebra', 'alpha', 'mango']);
});

it('flags required parameters and skips optional ones from the required list', function (): void {
    $cls = new
        #[ToolParameter(name: 'a', type: 'string', description: 'req', required: true)]
        #[ToolParameter(name: 'b', type: 'string', description: 'opt', required: false)]
        #[ToolParameter(name: 'c', type: 'string', description: 'req', required: true)]
    class {};

    expect(ToolParameterSchemaBuilder::build($cls)['required'])->toBe(['a', 'c']);
});

it('emits enum on parameter when constrained', function (): void {
    $cls = new
        #[ToolParameter(name: 'level', type: 'string', description: 'Verbosity', required: true, enum: ['low', 'high'])]
    class {};

    $schema = ToolParameterSchemaBuilder::build($cls);

    expect($schema['properties']['level']['enum'])->toBe(['low', 'high']);
});

it('synthesizes an `action` discriminator from #[ToolOperation] declarations', function (): void {
    $cls = new
        #[ToolOperation(name: 'list_events', description: 'List events')]
        #[ToolOperation(name: 'create_event', description: 'Create event')]
        #[ToolOperation(name: 'delete_event', description: 'Delete event')]
        #[ToolParameter(name: 'summary', type: 'string', description: 'Event title', required: false)]
    class {};

    $schema = ToolParameterSchemaBuilder::build($cls);

    expect(array_keys($schema['properties']))->toBe(['action', 'summary'])
        ->and($schema['properties']['action']['type'])->toBe('string')
        ->and($schema['properties']['action']['enum'])->toBe(['list_events', 'create_event', 'delete_event'])
        ->and($schema['required'])->toBe(['action']);
});

it('does NOT synthesize a discriminator when the tool has only one operation', function (): void {
    // Single-op tools have no LLM-facing choice to make. HasOperations falls
    // back to the only declared op when the argument is absent. Avoiding the
    // synthesized property keeps the LLM payload minimal and matches the
    // hand-rolled convention used by CalculatorTool / ReadUrlTool / etc.
    $cls = new
        #[ToolOperation(name: 'calculate', description: 'Calculate')]
        #[ToolParameter(name: 'expression', type: 'string', description: 'Expr', required: true)]
    class {};

    $schema = ToolParameterSchemaBuilder::build($cls);

    expect(array_keys($schema['properties']))->toBe(['expression'])
        ->and($schema['required'])->toBe(['expression']);
});

it('uses a custom discriminatorKey when the first #[ToolOperation] declares one', function (): void {
    $cls = new
        #[ToolOperation(name: 'search', description: 'Search', discriminatorKey: 'op')]
        #[ToolOperation(name: 'top_news', description: 'Top news', discriminatorKey: 'op')]
    class {};

    $schema = ToolParameterSchemaBuilder::build($cls);

    expect(array_keys($schema['properties']))->toBe(['op'])
        ->and($schema['properties']['op']['enum'])->toBe(['search', 'top_news'])
        ->and($schema['required'])->toBe(['op']);
});

it('emits minimum, maximum, format, items, and default when set', function (): void {
    $cls = new
        #[ToolParameter(name: 'days', type: 'integer', description: 'Days', required: false, minimum: 1, maximum: 3)]
        #[ToolParameter(name: 'date', type: 'string', description: 'Date', required: false, format: 'date')]
        #[ToolParameter(name: 'tags', type: 'array', description: 'Tags', required: false, items: ['type' => 'string'])]
        #[ToolParameter(name: 'limit', type: 'integer', description: 'Limit', required: false, default: 10)]
    class {};

    $schema = ToolParameterSchemaBuilder::build($cls);

    expect($schema['properties']['days']['minimum'])->toBe(1)
        ->and($schema['properties']['days']['maximum'])->toBe(3)
        ->and($schema['properties']['date']['format'])->toBe('date')
        ->and($schema['properties']['tags']['items'])->toBe(['type' => 'string'])
        ->and($schema['properties']['limit']['default'])->toBe(10);
});

it('omits required flag when a default is provided', function (): void {
    $cls = new
        #[ToolParameter(name: 'limit', type: 'integer', description: 'Limit', required: true, default: 10)]
        #[ToolParameter(name: 'q', type: 'string', description: 'Query', required: true)]
    class {};

    expect(ToolParameterSchemaBuilder::build($cls)['required'])->toBe(['q']);
});

it('throws when ToolParameter is constructed with an unknown type', function (): void {
    expect(fn() => new ToolParameter(name: 'x', type: 'date', description: 'd'))
        ->toThrow(InvalidArgumentException::class);
});

it('walks the inheritance chain to collect #[ToolParameter] from parent classes', function (): void {
    // Mirrors the AbstractMemoryTool → AgentMemoryTool relationship: parameters
    // declared on the abstract base must show up in the concrete subclass's
    // schema. The builder walks `ReflectionClass::getParentClass()` until it
    // hits the root so shared parameter declarations work as inheritance.
    $schema = ToolParameterSchemaBuilder::build(SchemaBuilderTestSubclass::class);

    // Concrete class declares `q`; the abstract parent declares `inherited`.
    expect(array_keys($schema['properties']))->toContain('q')
        ->and(array_keys($schema['properties']))->toContain('inherited');
});

it('walks the inheritance chain to collect #[ToolOperation] from parent classes', function (): void {
    // Same inheritance walk applies to operations — if a subclass adds more
    // operations the discriminator enum should list them all.
    $schema = ToolParameterSchemaBuilder::build(SchemaBuilderTestSubclass::class);

    expect($schema['properties']['action']['enum'])->toContain('run')
        ->and($schema['properties']['action']['enum'])->toContain('stop');
});

it('accepts a class-string in addition to an instance', function (): void {
    $schema = ToolParameterSchemaBuilder::build(SchemaBuilderTestSubclass::class);
    $schemaFromInstance = ToolParameterSchemaBuilder::build(new SchemaBuilderTestSubclass());

    expect($schema)->toBe($schemaFromInstance);
});

#[ToolParameter(name: 'inherited', type: 'string', description: 'From parent', required: false)]
abstract class SchemaBuilderTestParent {}

#[Tool(name: 'fixture_subclass', description: 'Builder test fixture')]
#[ToolOperation(name: 'run', description: 'Run it')]
#[ToolOperation(name: 'stop', description: 'Stop it')]
#[ToolParameter(name: 'q', type: 'string', description: 'Query', required: true)]
final class SchemaBuilderTestSubclass extends SchemaBuilderTestParent {}
