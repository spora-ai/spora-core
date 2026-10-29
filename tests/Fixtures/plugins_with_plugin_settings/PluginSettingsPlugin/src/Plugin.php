<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\PluginSettingsPlugin;

use Spora\Plugins\AbstractPlugin;

/**
 * Fixture plugin contributing a settings provider.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Plugin Settings Plugin';
    }

    /**
     * @return list<class-string<\Spora\Settings\PluginSettingsProviderInterface>>
     */
    public function pluginSettingProviders(): array
    {
        return [StubPluginSettingsProvider::class];
    }
}
