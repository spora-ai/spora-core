<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;

/**
 * Builds {@see Config} from the three layers available before the database
 * opens: registry defaults, `config.php`, and `.env` / the OS environment.
 *
 * The fourth layer is applied later, by `Config::applySettingsOverlay()`.
 *
 * The plugin-contributed settings are a fifth layer, and they are deferred:
 * see {@see self::create()}.
 */
final class ConfigFactory
{
    /** @var array<string, mixed>|null The loaded `config.php`, read once. */
    private ?array $configFile = null;

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly Paths $paths,
    ) {}

    public function create(): Config
    {
        ['values' => $values, 'sources' => $sources] = $this->resolveValues($this->registry->coreDefinitions());

        $config = new Config($values, $sources);

        // The plugin-contributed settings are a second pass. A plugin's
        // settings provider may depend on the config — it reads the app URL
        // and the credentials to describe its settings — and the container
        // refuses that cycle, so the providers can only be resolved once the
        // config exists.
        $config->deferLayer(fn(): array => $this->resolveValues($this->registry->pluginDefinitions()));

        return $config;
    }

    /**
     * The boot value of every given setting, and where it came from.
     *
     * @param array<string, SettingDefinition> $definitions
     * @return array{values: array<string, mixed>, sources: array<string, string>}
     */
    private function resolveValues(array $definitions): array
    {
        $file = $this->configFileValues($definitions);
        $env  = $this->envValues($definitions);

        $values = [];
        $sources = [];
        foreach ($definitions as $key => $definition) {
            $raw = $env[$key] ?? $file[$key] ?? null;
            if ($raw === null) {
                $values[$key] = $this->registry->clamp($definition, $definition->default);
                $sources[$key] = Config::SOURCE_DEFAULT;

                continue;
            }
            $values[$key] = $this->registry->coerce($definition, $raw);
            $sources[$key] = array_key_exists($key, $env)
                ? Config::SOURCE_ENV
                : Config::SOURCE_CONFIG_FILE;
        }

        return ['values' => $values, 'sources' => $sources];
    }

    /**
     * @param array<string, SettingDefinition> $definitions
     * @return array<string, string> keyed by `SPORA_*`
     */
    private function envValues(array $definitions): array
    {
        $out = [];
        foreach (array_keys($definitions) as $key) {
            // `?? null` rather than a truthiness check, because phpdotenv's default
            // adapter writes into `$_ENV` and `??` finds it there. An env var
            // set to the empty string therefore arrives as `''`, not as null
            // — which is how `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES=`
            // disables image uploads outright. That is the documented meaning
            // of an empty value, not an accident.
            $raw = $_ENV[$key] ?? (getenv($key) ?: null);
            if ($raw !== null) {
                $out[$key] = $raw;
            }
        }

        return $out;
    }

    /**
     * `config.php` is read in two shapes, because the deployed ones are in the
     * old shape and rewriting every deployment is a breaking change:
     *
     *  - the legacy nested keys an operator may already have written
     *    (`'db_driver'`, `'media_archive' => ['fetch_timeout_seconds' => 45]`),
     *    translated by {@see self::legacyKeyMap()};
     *  - the flat `SPORA_*` keys the skeleton repos will move to.
     *
     * A key present in both shapes resolves to the flat one.
     *
     * @param array<string, SettingDefinition> $definitions
     * @return array<string, mixed>
     */
    private function configFileValues(array $definitions): array
    {
        $loaded = $this->loadedConfigFile();
        if ($loaded === []) {
            return [];
        }

        $out = [];
        foreach (self::legacyKeyMap() as $legacyPath => $newKey) {
            $value = self::dig($loaded, $legacyPath);
            if ($value !== null) {
                $out[$newKey] = $value;
            }
        }
        foreach (array_keys($definitions) as $key) {
            if (array_key_exists($key, $loaded)) {
                $out[$key] = $loaded[$key];
            }
        }

        return $out;
    }

    /**
     * The loaded `config.php`, read once.
     *
     * The file is read for the core settings and again for the plugin ones;
     * reading it twice would run the file twice.
     *
     * @return array<string, mixed>
     */
    private function loadedConfigFile(): array
    {
        if ($this->configFile !== null) {
            return $this->configFile;
        }

        $configPath = $_ENV['SPORA_CONFIG_PATH'] ?? (getenv('SPORA_CONFIG_PATH') ?: $this->paths->config());

        return $this->configFile = UserConfig::load($configPath);
    }

    private static function dig(array $source, string $dotPath): mixed
    {
        $cursor = $source;
        foreach (explode('.', $dotPath) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * The settings whose legacy `config.php` path is ambiguous — both a flat
     * and a nested spelling reach the same key, and both are accepted.
     *
     * @var list<string>
     */
    public const MAIL_ALIAS_TARGETS = [
        'SPORA_MAIL_DRIVER',
        'SPORA_MAIL_HOST',
        'SPORA_MAIL_PORT',
        'SPORA_MAIL_USERNAME',
        'SPORA_MAIL_PASSWORD',
        'SPORA_MAIL_FROM',
        'SPORA_MAIL_FROM_NAME',
        'SPORA_MAIL_ENCRYPTION',
    ];

    /**
     * Legacy `config.php` path => current `SPORA_*` key.
     *
     * Kept until every deployment's `config.php` has been regenerated, which
     * is why the skeleton repos move to flat keys last rather than first.
     *
     * Note `media_archive.fetch_timeout_seconds` maps to
     * `SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT`, not `..._SECONDS`: the env var that
     * has always overridden it does not carry the suffix, and an operator with
     * the env var set must not see it silently ignored.
     *
     * @return array<string, string>
     */
    public static function legacyKeyMap(): array
    {
        return [
            'db_driver'                      => 'SPORA_DB_DRIVER',
            'db_path'                        => 'SPORA_DB_PATH',
            'db_host'                        => 'SPORA_DB_HOST',
            'db_port'                        => 'SPORA_DB_PORT',
            'db_name'                        => 'SPORA_DB_NAME',
            'db_user'                        => 'SPORA_DB_USER',
            'db_password'                    => 'SPORA_DB_PASSWORD',
            'sqlite_busy_timeout'            => 'SPORA_SQLITE_BUSY_TIMEOUT',
            'key_path'                       => 'SPORA_KEY_PATH',
            'allow_registration'             => 'SPORA_ALLOW_REGISTRATION',
            'allow_group_creation'           => 'SPORA_ALLOW_GROUP_CREATION',
            'app_env'                        => 'SPORA_APP_ENV',
            'app_name'                       => 'SPORA_APP_NAME',
            'log_level'                      => 'SPORA_LOG_LEVEL',
            'log_path'                       => 'SPORA_LOG_PATH',
            'worker_runtime_mode'            => 'SPORA_WORKER_RUNTIME_MODE',
            'tick_lease_seconds'             => 'SPORA_TICK_LEASE_SECONDS',
            'worker_stale_minutes'           => 'SPORA_WORKER_STALE_MINUTES',
            'max_workers'                    => 'SPORA_MAX_WORKERS',
            'llm_timeout'                    => 'SPORA_LLM_TIMEOUT',
            'tool_http_timeout'              => 'SPORA_TOOL_HTTP_TIMEOUT',
            'tools_group_cascade_enabled'    => 'SPORA_TOOLS_GROUP_CASCADE_ENABLED',
            'mercure_url'                    => 'SPORA_MERCURE_URL',
            'mercure_publish_url'            => 'SPORA_MERCURE_PUBLISH_URL',
            'mercure_jwt_key'                => 'SPORA_MERCURE_JWT_KEY',
            'app_url'                        => 'SPORA_APP_URL',
            'app_prefix'                     => 'SPORA_APP_PREFIX',
            'plugin_install_enabled'         => 'SPORA_PLUGIN_INSTALL_ENABLED',
            'plugin_catalog_enabled'         => 'SPORA_PLUGIN_CATALOG_ENABLED',
            'plugin_catalog_ttl'             => 'SPORA_PLUGIN_CATALOG_TTL',
            'composer_binary'                => 'SPORA_COMPOSER_BINARY',
            'asset_store.mode'               => 'SPORA_ASSET_STORE_MODE',
            'asset_store.auto_threshold_bytes' => 'SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES',
            'asset_store.max_bytes'          => 'SPORA_ASSET_STORE_MAX_BYTES',
            'media_archive.promote_external' => 'SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL',
            'media_archive.fetch_timeout_seconds' => 'SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT',
            'media_archive.max_promote_bytes' => 'SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES',
            'media_archive.ffprobe_enabled'  => 'SPORA_MEDIA_ARCHIVE_FFPROBE_ENABLED',
            'media_archive.allowed_image_types' => 'SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES',
            'notifications.email_enabled'    => 'SPORA_NOTIFICATIONS_EMAIL_ENABLED',
            'mail.driver'                    => 'SPORA_MAIL_DRIVER',
            'mail.host'                      => 'SPORA_MAIL_HOST',
            'mail.port'                      => 'SPORA_MAIL_PORT',
            'mail.username'                  => 'SPORA_MAIL_USERNAME',
            'mail.password'                  => 'SPORA_MAIL_PASSWORD',
            'mail.from_address'              => 'SPORA_MAIL_FROM',
            'mail.from_name'                 => 'SPORA_MAIL_FROM_NAME',
            'mail.encryption'                => 'SPORA_MAIL_ENCRYPTION',

            // The flat keys come second so they win. `SystemMailer` reads
            // the flat shape; the nested `mail` block an older deployment
            // carries in its `config.php` was never read by anything. Both
            // are accepted so neither shape is silently dropped, and the one
            // that demonstrably worked takes precedence.
            'mail_driver'                    => 'SPORA_MAIL_DRIVER',
            'mail_host'                      => 'SPORA_MAIL_HOST',
            'mail_port'                      => 'SPORA_MAIL_PORT',
            'mail_username'                  => 'SPORA_MAIL_USERNAME',
            'mail_password'                  => 'SPORA_MAIL_PASSWORD',
            'mail_from'                      => 'SPORA_MAIL_FROM',
            'mail_from_name'                 => 'SPORA_MAIL_FROM_NAME',
            'mail_encryption'                => 'SPORA_MAIL_ENCRYPTION',
        ];
    }
}
