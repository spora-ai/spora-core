<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ConfigDependentPlugin;

use Spora\Plugins\AbstractPlugin;

/**
 * Fixture plugin contributing a settings provider that needs the config.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Config Dependent Settings Plugin';
    }

    /**
     * @return list<class-string<\Spora\Settings\PluginSettingsProviderInterface>>
     */
    public function pluginSettingProviders(): array
    {
        return [ConfigDependentProvider::class, RegistryReadingProvider::class];
    }
}
