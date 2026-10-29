<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\PluginSettingsPlugin;

use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\SettingDefinition;

/**
 * Settings provider for a plugin with the slug `social`.
 *
 * The keys are given without a prefix on purpose: the registry builds the
 * full key, so the rule is enforced in one place.
 */
final class StubPluginSettingsProvider implements PluginSettingsProviderInterface
{
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
                label: 'LinkedIn client ID',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                description: 'The client id of the LinkedIn app.',
            ),
            new SettingDefinition(
                key: 'linkedin_client_secret',
                label: 'LinkedIn client secret',
                type: SettingDefinition::TYPE_PASSWORD,
                default: '',
                secret: true,
                description: 'The client secret of the LinkedIn app.',
            ),
        ];
    }
}
