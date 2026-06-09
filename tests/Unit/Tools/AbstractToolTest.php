<?php

declare(strict_types=1);

use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

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

#[Tool(name: 'fixture_abstract_tool', description: 'AbstractTool unit test fixture')]
#[ToolOperation(name: 'run', description: 'Run')]
#[ToolOperation(name: 'stop', description: 'Stop')]
#[ToolParameter(name: 'q', type: 'string', description: 'Query', required: true)]
final class AbstractToolTestFixture extends AbstractTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'fixture';
    }
}

#[Tool(name: 'fixture_di_tool', description: 'AbstractTool DI fixture')]
#[ToolOperation(name: 'run', description: 'Run')]
final class AbstractToolTestWithDi extends AbstractTool
{
    public function __construct(private readonly string $prefix) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return new ToolResult(true, "{$this->prefix}: ok");
    }

    public function describeAction(array $arguments): string
    {
        return 'di fixture';
    }
}
