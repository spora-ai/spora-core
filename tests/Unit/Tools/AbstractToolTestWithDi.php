<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ValueObjects\ToolResult;

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
