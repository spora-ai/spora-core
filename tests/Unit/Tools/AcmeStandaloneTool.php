<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Declares the same keys as {@see AcmeChatTool} but names no group — the
 * control for every shared-layer test.
 */
#[Tool(name: 'acme_standalone', description: 'Acme tool with no shared group')]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password', required: true)]
#[ToolSetting(key: 'region', label: 'Region', type: 'text')]
#[ToolSetting(key: 'retries', label: 'Retries', type: 'text', default: '3')]
final class AcmeStandaloneTool implements ToolInterface
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'Call Acme directly';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
