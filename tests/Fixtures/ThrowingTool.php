<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;
use Spora\Tools\Attributes\InputTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\InputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'throwing_tool', description: 'A stub tool that always throws')]
#[InputTool]
final class ThrowingTool implements InputToolInterface
{
    public function execute(array $arguments): ToolResult
    {
        throw new RuntimeException('Community plugin exploded!');
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
