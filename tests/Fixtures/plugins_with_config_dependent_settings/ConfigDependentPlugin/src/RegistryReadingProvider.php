<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ConfigDependentPlugin;

use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;

/**
 * A settings provider that reads the settings registry.
 *
 * The registry is what asks the provider for its settings, so a provider
 * that reads the registry in its constructor re-enters the collection. The
 * registry has to survive that: the plugin's settings must not be dropped
 * because the provider looked at the set while it was being built.
 */
final class RegistryReadingProvider implements PluginSettingsProviderInterface
{
    public function __construct(
        private readonly SettingsRegistry $registry,
    ) {
        $this->registry->all();
    }

    public function slug(): string
    {
        return 'registry';
    }

    /**
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        return [
            new SettingDefinition(
                key: 'last_key',
                label: 'Last key',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
            ),
        ];
    }
}
