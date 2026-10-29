<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ConfigDependentPlugin;

use Spora\Core\Config;
use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\SettingDefinition;

/**
 * A settings provider that needs the configuration.
 *
 * The shape of `spora-plugin-social`'s provider: it reads the app URL to
 * describe the setting the redirect URL is registered on. Resolving such a
 * provider while the configuration is being built closes the cycle
 * `Config → provider → Config`, and the container refuses it.
 */
final class ConfigDependentProvider implements PluginSettingsProviderInterface
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function slug(): string
    {
        return 'social';
    }

    /**
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        return [
            new SettingDefinition(
                key: 'linkedin_client_id',
                label: 'LinkedIn client id',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                description: 'The client id of the LinkedIn app. Register '
                    . ($this->config->getString('SPORA_APP_URL') ?? 'no address')
                    . ' as the redirect URL.',
            ),
        ];
    }
}
