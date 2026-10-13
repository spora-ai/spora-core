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

/**
 * Consumes the shared group but declares `api_key` as plain text, where
 * {@see AcmeSharedSettings} declares it as a password.
 *
 * A realistic plugin bug. It exists so the `inherited` masking can be proven
 * to key off the *group's* schema: masking by the tool's schema would hand
 * this tool's form the shared credential in clear.
 */
#[Tool(name: 'acme_mistyped', description: 'Acme tool that mistypes the shared key')]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'text', required: true)]
#[ToolSetting(key: 'region', label: 'Region', type: 'text')]
#[ToolSetting(key: 'retries', label: 'Retries', type: 'text', default: '3')]
final class AcmeMistypedTool implements ToolInterface, ConsumesSharedSettings
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
