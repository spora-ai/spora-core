<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use RuntimeException;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ConsumesSharedSettings;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\ReadsSharedSettings;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * A consuming tool the resolver must be able to read without instantiating:
 * the constructor throws, so anything that builds one to ask which group it
 * uses would fail on a real request.
 */
#[Tool(name: 'acme_unconstructable', description: 'Acme tool that cannot be constructed')]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password')]
#[ToolSetting(key: 'unrelated_key', label: 'Unrelated', type: 'text')]
final class AcmeUnconstructableTool implements ToolInterface, ConsumesSharedSettings
{
    use ReadsSharedSettings;

    public const SHARED_SETTINGS_GROUP = 'not_a_declared_group';

    public function __construct()
    {
        throw new RuntimeException('This tool is never constructed in production.');
    }

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
