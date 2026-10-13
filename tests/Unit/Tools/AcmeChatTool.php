<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ConsumesSharedSettings;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\ReadsSharedSettings;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'acme_chat', description: 'Acme tool that falls back to the shared group')]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password', required: true)]
#[ToolSetting(key: 'region', label: 'Region', type: 'text')]
#[ToolSetting(key: 'retries', label: 'Retries', type: 'text', default: '3')]
final class AcmeChatTool implements ToolInterface, ConsumesSharedSettings
{
    use ReadsSharedSettings;

    public const SHARED_SETTINGS_GROUP = 'acme';

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
        return 'Call Acme';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
