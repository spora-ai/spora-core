<?php

declare(strict_types=1);

use Spora\Core\ConfigFactory;
use Spora\Core\Paths;
use Spora\Settings\SettingsRegistry;

/**
 * The migration from a nested config array to a flat `SPORA_*` namespace is
 * only safe if every key the application used to read still resolves.
 *
 * The list below is the complete set of literal `$config['…']` and
 * `get('config')['…']` reads at the last commit before the migration
 * (9960a46a). Each one has to reach a declared setting, or an operator
 * upgrading with an existing `config.php` silently loses a value.
 */
function legacyReadPaths(): array
{
    return [
        'allow_group_creation', 'app_env', 'app_prefix', 'app_url', 'composer_binary',
        'db_driver', 'db_host', 'db_name', 'db_password', 'db_path', 'db_port', 'db_user',
        'key_path', 'log_level', 'log_path', 'sqlite_busy_timeout',
        'llm_timeout', 'tool_http_timeout', 'worker_runtime_mode', 'tick_lease_seconds',
        'worker_stale_minutes', 'max_workers', 'tools_group_cascade_enabled',
        'plugin_install_enabled', 'plugin_catalog_enabled', 'plugin_catalog_ttl',
        'mercure_url', 'mercure_publish_url', 'mercure_jwt_key', 'mercure_legacy_user_topic',
        'notifications.email_enabled',
        'media_archive.fetch_timeout_seconds', 'media_archive.allowed_image_types',
        'media_archive.promote_external', 'media_archive.max_promote_bytes',
        'media_archive.ffprobe_enabled',
        'asset_store.mode', 'asset_store.auto_threshold_bytes', 'asset_store.max_bytes',
        // `SystemMailer` read the flat shape; the nested `mail` block that
        // `install.php` writes into a fresh `config.php` was read by nothing.
        // Both are mapped, and both spellings are asserted below.
        'mail_driver', 'mail_encryption', 'mail_from', 'mail_from_name', 'mail_host',
        'mail_password', 'mail_port', 'mail_username',
        'mail.driver', 'mail.host', 'mail.port', 'mail.username', 'mail.password',
        'mail.from_address', 'mail.from_name', 'mail.encryption',
    ];
}

function registryKeys(): array
{
    return array_keys((new SettingsRegistry(new Paths(BASE_PATH)))->all());
}

it('maps every legacy config.php read path onto a declared setting', function (): void {
    $map = ConfigFactory::legacyKeyMap();
    $declared = registryKeys();

    $missing = [];
    foreach (legacyReadPaths() as $legacyPath) {
        if (!isset($map[$legacyPath])) {
            $missing[] = $legacyPath;
            continue;
        }
        if (!in_array($map[$legacyPath], $declared, true)) {
            $missing[] = sprintf('%s -> %s (undeclared)', $legacyPath, $map[$legacyPath]);
        }
    }

    expect($missing)->toBe([]);
});

it('maps no legacy path onto a setting that does not exist', function (): void {
    $declared = registryKeys();

    $stale = array_filter(
        ConfigFactory::legacyKeyMap(),
        static fn(string $target): bool => !in_array($target, $declared, true),
    );

    expect($stale)->toBe([]);
});

it('accepts both mail config.php spellings', function (): void {
    $map = ConfigFactory::legacyKeyMap();

    expect($map['mail_driver'] ?? null)->toBe('SPORA_MAIL_DRIVER')
        ->and($map['mail.driver'] ?? null)->toBe('SPORA_MAIL_DRIVER')
        ->and($map['mail.from_address'] ?? null)->toBe('SPORA_MAIL_FROM')
        ->and($map['mail_from'] ?? null)->toBe('SPORA_MAIL_FROM');
});

it('lets the flat mail spelling win over the nested one', function (string $flat, string $nested): void {
    // `SystemMailer` read `mail_driver`; the `mail` block was dead. A
    // deployment that only ever had the nested block never had working mail
    // config, and one that only has the flat shape must not lose it now.
    $paths = array_keys(ConfigFactory::legacyKeyMap());
    $flatAt = array_search($flat, $paths, true);
    $nestedAt = array_search($nested, $paths, true);

    expect($flatAt)->not->toBeFalse()
        ->and($nestedAt)->not->toBeFalse()
        ->and($flatAt)->toBeGreaterThan($nestedAt);
})->with([
    ['mail_driver', 'mail.driver'],
    ['mail_host', 'mail.host'],
    ['mail_port', 'mail.port'],
    ['mail_username', 'mail.username'],
    ['mail_password', 'mail.password'],
    ['mail_from', 'mail.from_address'],
    ['mail_from_name', 'mail.from_name'],
    ['mail_encryption', 'mail.encryption'],
]);

it('maps fetch_timeout_seconds to the key the env var has always used', function (): void {
    // `SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT` does not carry the suffix. An
    // operator with that variable set must not see it ignored.
    expect(ConfigFactory::legacyKeyMap()['media_archive.fetch_timeout_seconds'])
        ->toBe('SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT');
});
