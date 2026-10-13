<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\SharedSettings;
use Spora\Tools\Attributes\ToolSetting;

/**
 * A shared credential group in the shape a plugin ships one: the settings are
 * declared on a class that is not a tool, and the values are stored against
 * this class's FQCN in `tool_configurations`.
 *
 * `api_key` is `required` so the D14 boundary is observable — a tool consuming
 * this group reports it missing or not depending only on whether the shared
 * layer was in the resolution.
 */
#[SharedSettings(
    group: 'acme',  // see self::ID
    label: 'Acme',
    description: 'Credentials shared by every Acme tool.',
    icon: 'key',
    order: 20,
    plugin: 'spora-plugin-acme',
)]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password', required: true)]
#[ToolSetting(key: 'region', label: 'Region', type: 'text')]
#[ToolSetting(key: 'retries', label: 'Retries', type: 'text', default: '3')]
final class AcmeSharedSettings
{
    /** Must match the `group:` above — the id the API addresses it by. */
    public const ID = 'acme';
}
