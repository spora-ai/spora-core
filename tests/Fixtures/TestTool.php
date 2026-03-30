<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\InputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'test_tool', description: 'A test tool')]
#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', scope: 'agent')]
#[ToolSetting(key: 'max_results', label: 'Max Results', type: 'text', scope: 'global')]
final class TestTool implements InputToolInterface
{
    public function execute(array $arguments): ToolResult
    {
        return new ToolResult(true, 'ok');
    }

    public function getParametersSchema(): array
    {
        return [];
    }
}
