<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SharedSettingsPlugin;

use Spora\Plugins\AbstractPlugin;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\SharedSettings;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ConsumesSharedSettings;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\ReadsSharedSettings;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Fixture plugin that contributes one shared settings group through the
 * `settingsClasses()` hook. The namespace is covered by the `Tests\` psr-4
 * entry in composer.json, so the manifest needs no autoload block.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Shared Settings Fixture';
    }

    /**
     * Flipped by the sidecar test to prove the loader reads the live plugin
     * object. PHP caches a loaded class for the life of the process, so
     * rewriting this file mid-test cannot demonstrate anything — the plugin
     * has to be able to change its answer without its manifest changing.
     */
    public static bool $includeSecondaryGroup = false;

    /** @return list<class-string> */
    public function settingsClasses(): array
    {
        return self::$includeSecondaryGroup
            ? [ProviderSettings::class, SecondarySettings::class]
            : [ProviderSettings::class];
    }
}

#[SharedSettings(group: 'fixture_secondary', label: 'Secondary')]
#[ToolSetting(key: 'token', label: 'Token', type: 'password')]
final class SecondarySettings {}

#[SharedSettings(group: 'fixture_provider', label: 'Fixture Provider', plugin: 'shared-settings-plugin')]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password', required: true)]
final class ProviderSettings {}

/**
 * A tool in the same plugin that consumes the group above, so the loader test
 * can assert the declaration resolves end to end rather than only that a
 * class-name string was collected.
 */
#[Tool(name: 'fixture_consumer', description: 'Consumes the fixture provider group')]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password', required: true)]
final class ConsumerTool implements ToolInterface, ConsumesSharedSettings
{
    use ReadsSharedSettings;

    public const SHARED_SETTINGS_GROUP = 'fixture_provider';

    public function execute(array $arguments, int $agentId, ?int $taskId = null, ?PrincipalContext $context = null): ToolResult
    {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'Call the fixture provider';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
