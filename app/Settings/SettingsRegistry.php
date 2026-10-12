<?php

declare(strict_types=1);

namespace Spora\Settings;

use Spora\Core\Paths;
use Spora\Core\RequestOrigin;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\PluginCatalogService;

/**
 * Every operator-facing setting, declared once, in one place.
 *
 * This replaces the `$defaults` array and the 36 `collectEnvOverrides()`
 * `$apply()` calls in `ContainerDefinitions`. Nothing checked that those two
 * agreed with each other or with `.env.example`, which is how drift survived:
 * a key added to `$defaults` with no `$apply()` line is silently
 * un-overridable by env, and a `$apply()` line with no `$defaults` entry
 * resolves only when the env var happens to be set.
 *
 * Resolution order, last wins:
 *
 *     registry defaults  <  config.php  <  .env / OS environment  <  DB
 *
 * The DB layer is absent at boot for the settings marked `bootOnly`, because
 * they are read before the database can be opened. That is a chicken-and-egg
 * guard, not an oversight — see {@see \Spora\Core\Config::applySettingsOverlay()}.
 *
 * Env vars that only redirect `Paths` (`SPORA_APP_DIR`, `SPORA_STORAGE_DIR`,
 * `SPORA_PLUGINS_DIR`, `SPORA_RECIPES_DIR`, `SPORA_CONFIG_PATH`,
 * `SPORA_ENV_FILE`) are deliberately NOT declared. They never entered the
 * config array, they are consumed while `Paths` itself is being built, and
 * showing an operator an editable-looking field for one would be a lie.
 */
final class SettingsRegistry
{
    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string, SettingDefinition> keyed by setting key
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?SettingDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return list<SettingDefinition>
     */
    public function forSection(string $section): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(SettingDefinition $d): bool => $d->section === $section,
        ));
    }

    /**
     * Keys that must never come from the database, because they are read
     * before it is open — or they are filesystem facts.
     *
     * @return list<string>
     */
    public function bootOnlyKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn(SettingDefinition $d): bool => $d->bootOnly,
        ));
    }

    /**
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn(SettingDefinition $d): bool => $d->secret,
        ));
    }

    /**
     * Normalise a raw value from any layer into this setting's declared type.
     *
     * Every layer goes through here, so `config.php`, `.env` and the DB cannot
     * disagree about what `'1'` means for a toggle.
     */
    public function coerce(SettingDefinition $d, mixed $raw): mixed
    {
        if ($raw === null) {
            return $d->default;
        }

        return match ($d->transform) {
            'image_types' => self::coerceImageTypes((string) $raw),
            // Normalised, not validated: the router strips the prefix from
            // incoming paths, so `/spora` and `spora` must resolve the same.
            'app_prefix' => RequestOrigin::normalizePrefix((string) $raw),
            default => match ($d->type) {
                SettingDefinition::TYPE_TOGGLE => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                SettingDefinition::TYPE_NUMBER => (int) $raw,
                SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST => (array) $raw,
                default => (string) $raw,
            },
        };
    }

    /**
     * Render a typed value for storage. Lists go to JSON so they round-trip
     * exactly; the comma-separated form is an input convenience for operators
     * typing into `.env`, not a storage format.
     */
    public function encode(SettingDefinition $d, mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    public function decode(SettingDefinition $d, string $raw): mixed
    {
        return match ($d->type) {
            SettingDefinition::TYPE_TOGGLE => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            SettingDefinition::TYPE_NUMBER => (int) $raw,
            SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST => $this->decodeList($raw),
            default => $raw,
        };
    }

    /**
     * Parse an operator-supplied image-type list.
     *
     * Whitespace is trimmed; tokens are lowercased; `jpg`/`jpeg` collapse to
     * `jpeg`; order and duplicates collapse to the first occurrence.
     *
     * SVG is rejected. This is a security control, not a formatting rule:
     * the result is the allowlist behind `MediaAllowedTypesService`, which
     * `MediaDerivativeService` calls the deliberate surface for images. An
     * unrendered SVG served from that allowlist is script execution.
     *
     * @return list<string>
     */
    private static function coerceImageTypes(string $raw): array
    {
        $tokens = preg_split('/[\s,]+/', trim($raw));
        if ($tokens === false) {
            return [];
        }
        $normalized = [];
        foreach ($tokens as $token) {
            $t = ltrim(strtolower(trim($token)), '.');
            if ($t === '' || $t === 'svg' || $t === 'svg+xml') {
                continue;
            }
            $normalized[] = $t === 'jpg' ? 'jpeg' : $t;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private function decodeList(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }

        // A value written before the list became JSON-encoded — and anything
        // an operator typed into `.env` — arrives comma-separated.
        return self::coerceImageTypes($raw);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function build(): array
    {
        $paths = $this->paths;
        $definitions = [];

        $add = static function (SettingDefinition $d) use (&$definitions): void {
            $definitions[$d->key] = $d;
        };

        // Application.
        $add(new SettingDefinition(
            key: 'SPORA_APP_NAME',
            label: 'Application name',
            type: SettingDefinition::TYPE_TEXT,
            default: 'Spora',
            section: 'general',
            description: 'Shown in the browser tab and in outbound email.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_APP_ENV',
            label: 'Environment',
            type: SettingDefinition::TYPE_SELECT,
            default: 'production',
            section: 'general',
            options: [
                ['value' => 'production', 'label' => 'Production'],
                ['value' => 'development', 'label' => 'Development'],
                ['value' => 'local', 'label' => 'Local'],
            ],
            description: 'Decides whether a 500 response carries a debug block. Read during boot, before the database is open.',
            bootOnly: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_LOG_LEVEL',
            label: 'Log level',
            type: SettingDefinition::TYPE_SELECT,
            default: 'WARNING',
            section: 'general',
            options: [
                ['value' => 'DEBUG', 'label' => 'Debug'],
                ['value' => 'INFO', 'label' => 'Info'],
                ['value' => 'WARNING', 'label' => 'Warning'],
                ['value' => 'ERROR', 'label' => 'Error'],
            ],
            description: 'Applies without a restart — the logger is built on first use, after boot.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_LOG_PATH',
            label: 'Log file path',
            type: SettingDefinition::TYPE_TEXT,
            default: $paths->storage('spora.log'),
            section: 'general',
            description: 'A filesystem path, so it cannot live in the database it would be written from.',
            bootOnly: true,
        ));

        // Access control.
        $add(new SettingDefinition(
            key: 'SPORA_ALLOW_REGISTRATION',
            label: 'Allow registration',
            type: SettingDefinition::TYPE_TOGGLE,
            default: true,
            section: 'access',
            description: 'When off, only existing accounts can sign in. Turn this off if signups should be invite-only.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_ALLOW_GROUP_CREATION',
            label: 'Allow group creation',
            type: SettingDefinition::TYPE_TOGGLE,
            default: true,
            section: 'access',
            description: 'When on, any signed-in user may create a group. Turn it off to keep creation admin-only; editing and deleting stay admin-only either way.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_NOTIFICATIONS_EMAIL_ENABLED',
            label: 'Email notifications',
            type: SettingDefinition::TYPE_TOGGLE,
            default: true,
            section: 'access',
            description: 'Master switch for notification email. Individual subscriptions still apply.',
        ));

        // Timeouts.
        $add(new SettingDefinition(
            key: 'SPORA_LLM_TIMEOUT',
            label: 'LLM request timeout',
            type: SettingDefinition::TYPE_NUMBER,
            default: 300,
            section: 'timeouts',
            description: 'Seconds to wait for a model response before giving up.',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_TOOL_HTTP_TIMEOUT',
            label: 'Tool HTTP timeout',
            type: SettingDefinition::TYPE_NUMBER,
            default: 30,
            section: 'timeouts',
            description: 'Default seconds for outbound HTTP from tools. A tool that declares its own timeout overrides this.',
            validation: '/^\d+$/',
        ));

        // Worker.
        $add(new SettingDefinition(
            key: 'SPORA_WORKER_RUNTIME_MODE',
            label: 'Worker runtime mode',
            type: SettingDefinition::TYPE_SELECT,
            default: 'server',
            section: 'worker',
            options: [
                ['value' => 'server', 'label' => 'Server — bin/spora worker:run drains the queue'],
                ['value' => 'client', 'label' => 'Client — browser SharedWorker, no daemon'],
            ],
            description: 'Where queued tasks are executed.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_TICK_LEASE_SECONDS',
            label: 'Tick lease',
            type: SettingDefinition::TYPE_NUMBER,
            default: 600,
            section: 'worker',
            description: 'Seconds the reaper waits past the last heartbeat before marking a run as failed.',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_WORKER_STALE_MINUTES',
            label: 'Stale worker threshold',
            type: SettingDefinition::TYPE_NUMBER,
            default: 60,
            section: 'worker',
            description: 'Minutes without a heartbeat before a worker is considered dead.',
            validation: '/^\d+$/',
            workerRestart: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAX_WORKERS',
            label: 'Maximum concurrent workers',
            type: SettingDefinition::TYPE_NUMBER,
            default: 0,
            section: 'worker',
            description: 'Zero means no limit.',
            validation: '/^\d+$/',
            workerRestart: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_TOOLS_GROUP_CASCADE_ENABLED',
            label: 'Group tool-settings cascade',
            type: SettingDefinition::TYPE_TOGGLE,
            default: false,
            section: 'worker',
            description: 'When on, a value set on a group also applies to that group\'s members.',
            workerRestart: true,
        ));

        // Plugins.
        $add(new SettingDefinition(
            key: 'SPORA_PLUGIN_INSTALL_ENABLED',
            label: 'Allow plugin installation',
            type: SettingDefinition::TYPE_TOGGLE,
            default: false,
            section: 'plugins',
            description: 'Required for installing plugins from the catalog. Turning this off does not uninstall anything.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_PLUGIN_CATALOG_ENABLED',
            label: 'Plugin catalog',
            type: SettingDefinition::TYPE_TOGGLE,
            default: true,
            section: 'plugins',
            description: 'When off, Spora makes no outbound requests to browse the catalog.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_PLUGIN_CATALOG_TTL',
            label: 'Catalog cache lifetime',
            type: SettingDefinition::TYPE_NUMBER,
            default: PluginCatalogService::DEFAULT_TTL_SECONDS,
            section: 'plugins',
            description: 'Seconds a catalog search stays cached on disk.',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_COMPOSER_BINARY',
            label: 'Composer binary',
            type: SettingDefinition::TYPE_TEXT,
            default: 'composer',
            section: 'plugins',
            description: 'Name on PATH, or an absolute path to composer.phar. On shared hosting there is usually no global composer.',
        ));

        // Asset storage.
        $add(new SettingDefinition(
            key: 'SPORA_ASSET_STORE_MODE',
            label: 'Asset storage mode',
            type: SettingDefinition::TYPE_SELECT,
            default: 'auto',
            section: 'media',
            options: [
                ['value' => 'auto', 'label' => 'Auto — inline small payloads, store large ones'],
                ['value' => 'data_url', 'label' => 'Inline — everything as a data: URL'],
                ['value' => 'local', 'label' => 'Stored — always written to disk'],
            ],
            description: 'Where tool-produced images, audio and video are kept.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES',
            label: 'Auto threshold',
            type: SettingDefinition::TYPE_NUMBER,
            default: 1024 * 1024,
            section: 'media',
            description: 'In auto mode, payloads at or below this are inlined.',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_ASSET_STORE_MAX_BYTES',
            label: 'Maximum asset size',
            type: SettingDefinition::TYPE_NUMBER,
            default: DatabaseAssetStore::MAX_BYTES,
            section: 'media',
            description: 'Hard ceiling per asset. Inlined payloads must stay under the media_assets column limit.',
            validation: '/^\d+$/',
        ));

        // Media archive.
        $add(new SettingDefinition(
            key: 'SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL',
            label: 'Store remote media locally',
            type: SettingDefinition::TYPE_TOGGLE,
            default: true,
            section: 'media',
            description: 'When on, a URL passed to a tool is downloaded and stored. Turn it off to keep only the link.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT',
            label: 'Media fetch timeout',
            type: SettingDefinition::TYPE_NUMBER,
            default: 30,
            section: 'media',
            description: 'Seconds allowed for the remote media fetch.',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES',
            label: 'Maximum remote media size',
            type: SettingDefinition::TYPE_NUMBER,
            default: 100 * 1024 * 1024,
            section: 'media',
            description: 'Larger responses are recorded as external rather than downloaded.',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MEDIA_ARCHIVE_FFPROBE_ENABLED',
            label: 'Extract media duration',
            type: SettingDefinition::TYPE_TOGGLE,
            default: false,
            section: 'media',
            description: 'Uses ffprobe when it is on PATH. Slower to ingest.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES',
            label: 'Allowed image types',
            type: SettingDefinition::TYPE_LIST,
            default: ['png', 'jpeg', 'webp'],
            section: 'media',
            transform: 'image_types',
            description: 'Image formats offered as attachments. SVG is always rejected and cannot be enabled here.',
        ));

        // Mail.
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_DRIVER',
            label: 'Mail driver',
            type: SettingDefinition::TYPE_SELECT,
            default: 'smtp',
            group: 'mail',
            section: 'mail',
            options: [
                ['value' => 'smtp', 'label' => 'SMTP'],
                ['value' => 'sendmail', 'label' => 'Sendmail'],
            ],
            description: 'How outbound mail is delivered.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_HOST',
            label: 'SMTP host',
            type: SettingDefinition::TYPE_TEXT,
            default: 'localhost',
            group: 'mail',
            section: 'mail',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_PORT',
            label: 'SMTP port',
            type: SettingDefinition::TYPE_NUMBER,
            default: 587,
            group: 'mail',
            section: 'mail',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_USERNAME',
            label: 'SMTP username',
            type: SettingDefinition::TYPE_TEXT,
            default: '',
            group: 'mail',
            section: 'mail',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_PASSWORD',
            label: 'SMTP password',
            type: SettingDefinition::TYPE_PASSWORD,
            default: '',
            group: 'mail',
            section: 'mail',
            secret: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_FROM',
            label: 'From address',
            type: SettingDefinition::TYPE_TEXT,
            default: 'noreply@example.com',
            group: 'mail',
            section: 'mail',
            validation: '/^[^@\s]+@[^@\s]+\.[^@\s]+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_FROM_NAME',
            label: 'From name',
            type: SettingDefinition::TYPE_TEXT,
            default: 'Spora',
            group: 'mail',
            section: 'mail',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAIL_ENCRYPTION',
            label: 'Encryption',
            type: SettingDefinition::TYPE_SELECT,
            default: 'tls',
            group: 'mail',
            section: 'mail',
            options: [
                ['value' => '', 'label' => 'None'],
                ['value' => 'tls', 'label' => 'TLS'],
                ['value' => 'ssl', 'label' => 'SSL'],
            ],
        ));

        // Mercure.
        $add(new SettingDefinition(
            key: 'SPORA_MERCURE_URL',
            label: 'Mercure hub URL',
            type: SettingDefinition::TYPE_TEXT,
            section: 'security',
            description: 'Live-update hub. Leave empty if you do not use live updates.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MERCURE_PUBLISH_URL',
            label: 'Mercure publish URL',
            type: SettingDefinition::TYPE_TEXT,
            section: 'security',
            description: 'Publisher endpoint, when it differs from the hub URL.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MERCURE_JWT_KEY',
            label: 'Mercure JWT key',
            type: SettingDefinition::TYPE_PASSWORD,
            section: 'security',
            secret: true,
            description: 'Shared secret used to sign Mercure update tokens.',
        ));

        // Database and keys — every one of these is read before the database
        // can be opened, which is why they are `bootOnly`.
        $add(new SettingDefinition(
            key: 'SPORA_DB_DRIVER',
            label: 'Database driver',
            type: SettingDefinition::TYPE_SELECT,
            default: 'sqlite',
            section: 'database',
            bootOnly: true,
            options: [
                ['value' => 'sqlite', 'label' => 'SQLite'],
                ['value' => 'mysql', 'label' => 'MySQL / MariaDB'],
            ],
        ));
        $add(new SettingDefinition(
            key: 'SPORA_DB_PATH',
            label: 'SQLite database file',
            type: SettingDefinition::TYPE_TEXT,
            default: $paths->storage('database.sqlite'),
            section: 'database',
            bootOnly: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_SQLITE_BUSY_TIMEOUT',
            label: 'SQLite busy timeout',
            type: SettingDefinition::TYPE_NUMBER,
            section: 'database',
            bootOnly: true,
            description: 'Milliseconds to wait for a locked database before failing.',
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_DB_HOST',
            label: 'Database host',
            type: SettingDefinition::TYPE_TEXT,
            section: 'database',
            bootOnly: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_DB_PORT',
            label: 'Database port',
            type: SettingDefinition::TYPE_NUMBER,
            section: 'database',
            bootOnly: true,
            validation: '/^\d+$/',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_DB_NAME',
            label: 'Database name',
            type: SettingDefinition::TYPE_TEXT,
            section: 'database',
            bootOnly: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_DB_USER',
            label: 'Database user',
            type: SettingDefinition::TYPE_TEXT,
            section: 'database',
            bootOnly: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_DB_PASSWORD',
            label: 'Database password',
            type: SettingDefinition::TYPE_PASSWORD,
            section: 'database',
            bootOnly: true,
            secret: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_KEY_PATH',
            label: 'Secret key file',
            type: SettingDefinition::TYPE_TEXT,
            section: 'security',
            bootOnly: true,
            description: 'Absolute path to the 32-byte secret.key. Changing it makes every stored secret undecryptable.',
            secret: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_APP_URL',
            label: 'Application URL',
            type: SettingDefinition::TYPE_TEXT,
            default: RequestOrigin::detect(),
            section: 'security',
            bootOnly: true,
            description: 'Used in links in outbound mail. Detected from the request when not set.',
        ));
        $add(new SettingDefinition(
            key: 'SPORA_APP_PREFIX',
            label: 'Application path prefix',
            type: SettingDefinition::TYPE_TEXT,
            default: RequestOrigin::detectWithPrefix()[1],
            section: 'security',
            bootOnly: true,
            description: 'Set when Spora is installed in a subdirectory, for example /spora.',
            transform: 'app_prefix',
        ));

        return $definitions;
    }
}