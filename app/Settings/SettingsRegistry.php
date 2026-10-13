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
 * agreed with each other, which is how drift survived:
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
    /**
     * Section key => operator-facing label, in display order.
     *
     * The UI groups by this, not by key shape, so flattening the nested
     * config keys cost the operator nothing.
     *
     * @var array<string, string>
     */
    /**
     * Non-negative integer, for every duration, count, size and timeout here.
     * A named constant so the rule lives once instead of being restated
     * thirteen times — and so a change to it cannot miss a key.
     */
    public const VALIDATION_UINT = '/^\d+$/';

    public const SECTION_LABELS = [
        'general'   => 'General',
        'access'    => 'Access',
        'timeouts'  => 'Timeouts',
        'worker'    => 'Worker',
        'plugins'   => 'Plugins',
        'media'     => 'Media',
        'mail'      => 'Mail',
        'database'  => 'Database',
        'security'  => 'Security',
    ];

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly Paths $paths) {}

    /**
     * @return array<string, SettingDefinition> keyed by setting key
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    /**
     * @return array<string, string>
     */
    public function sectionLabels(): array
    {
        return self::SECTION_LABELS;
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
     * Normalise a raw value into this setting's declared type.
     *
     * `config.php` and `.env` come through here. The database is read by
     * {@see self::decode()}, which normalises list and toggle forms but applies
     * no `transform` — so the two can disagree about a key that has one. A row
     * written through {@see \Spora\Settings\SettingsRepository::put()} is
     * canonical already, because that path coerces before it encodes; one
     * inserted by any other means comes back un-normalised.
     */
    public function coerce(SettingDefinition $d, mixed $raw): mixed
    {
        if ($raw === null) {
            return $d->default;
        }
        if (in_array($d->type, [SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST], true)) {
            return $this->normalizeList($d, $raw);
        }

        return match ($d->transform) {
            // Normalised, not validated: the router strips the prefix from
            // incoming paths, so `/spora` and `spora` must resolve the same.
            'app_prefix' => RequestOrigin::normalizePrefix((string) $raw),
            // `php_mail` was the long-standing fallback and resolves to
            // `sendmail://default` — the same DSN as `sendmail`. Canonicalising
            // it here means an operator's stored value and the registry
            // default are one name, which is what lets the admin page offer a
            // closed set of drivers instead of accepting whatever was typed.
            'mail_driver' => match (strtolower(trim((string) $raw))) {
                'php_mail' => 'sendmail',
                default => strtolower(trim((string) $raw)),
            },
            default => match ($d->type) {
                SettingDefinition::TYPE_TOGGLE => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                SettingDefinition::TYPE_NUMBER => (int) $raw,
                default => (string) $raw,
            },
        };
    }

    /**
     * Render a value for storage. Lists go to JSON so they round-trip exactly;
     * the comma-separated form is an input convenience for operators typing into
     * `.env`, not a storage format.
     *
     * Takes no definition because nothing about it needs one — shape follows the
     * value's PHP type, and the coercion to that type happened in
     * {@see self::coerce()}.
     */
    public function encode(mixed $value): string
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
        if (in_array($d->type, [SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST], true)) {
            return $this->normalizeList($d, $this->maybeJsonDecode($raw));
        }

        return match ($d->type) {
            SettingDefinition::TYPE_TOGGLE => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            SettingDefinition::TYPE_NUMBER => (int) $raw,
            default => $raw,
        };
    }

    /**
     * Split a delimited string, or flatten an array, into a list — applying
     * the setting's transform either way.
     *
     * Every input form has to go through here. A list arrives as comma- or
     * whitespace-separated text from `.env`, as a textarea's raw text from
     * the admin page, as JSON from the database, and as a real array from a
     * JSON request body. Filtering only the text form would let the others
     * past a rejection rule.
     *
     * @return list<string>
     */
    private function normalizeList(SettingDefinition $d, mixed $raw): array
    {
        if (is_array($raw)) {
            $raw = implode(',', array_map(static fn(mixed $v): string => (string) $v, $raw));
        }

        return $d->transform === 'image_types'
            ? self::coerceImageTypes((string) $raw)
            : self::tokenize((string) $raw);
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            static fn(string $p): bool => $p !== '',
        )));
    }

    /**
     * Lists are stored as JSON, but a value can also arrive as delimited text
     * from `.env` or the admin page. Only a genuine JSON array is unwrapped —
     * a bare token like `png` is not valid JSON and must stay a string.
     */
    private function maybeJsonDecode(string $raw): mixed
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $raw;
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
            // A token can arrive wrapped in JSON or quoting — the admin UI
            // renders a `list` setting as a textarea, and a list that came
            // back through one is re-tokenised from whatever the operator
            // left behind. `trim()` strips whitespace, not the quote
            // characters or the brackets a JSON array carries, and a
            // `"svg"` token would otherwise sail past the check below
            // because it does not *start with* `svg`.
            //
            // This is a security control, so it must hold for the spelling
            // the UI produces as readily as for the one a person types.
            $t = strtolower(trim($token, " \t\n\r\0\x0B\"'[]"));
            $t = ltrim($t, '.');
            if ($t === '') {
                continue;
            }
            // Matched by suffix rather than by equality. `svg`, `svgz`,
            // `image/svg+xml` and a leading-dotted `.svg` are all the same
            // format, and an allowlist that filtered only the exact string
            // `svg` would filter the one spelling an operator is least likely
            // to type.
            $bare = str_contains($t, '/') ? substr($t, (int) strrpos($t, '/') + 1) : $t;
            if (str_starts_with($bare, 'svg')) {
                continue;
            }
            $normalized[] = $bare === 'jpg' ? 'jpeg' : $bare;
        }

        return array_values(array_unique($normalized));
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
            validation: self::VALIDATION_UINT,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_TOOL_HTTP_TIMEOUT',
            label: 'Tool HTTP timeout',
            type: SettingDefinition::TYPE_NUMBER,
            default: 30,
            section: 'timeouts',
            description: 'Default seconds for outbound HTTP from tools. A tool that declares its own timeout overrides this.',
            validation: self::VALIDATION_UINT,
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
            validation: self::VALIDATION_UINT,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_WORKER_STALE_MINUTES',
            label: 'Stale worker threshold',
            type: SettingDefinition::TYPE_NUMBER,
            default: 60,
            section: 'worker',
            description: 'Minutes without a heartbeat before a worker is considered dead.',
            validation: self::VALIDATION_UINT,
            workerRestart: true,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MAX_WORKERS',
            label: 'Maximum concurrent workers',
            type: SettingDefinition::TYPE_NUMBER,
            default: 0,
            section: 'worker',
            description: 'Zero means no limit.',
            validation: self::VALIDATION_UINT,
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
            validation: self::VALIDATION_UINT,
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
            validation: self::VALIDATION_UINT,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_ASSET_STORE_MAX_BYTES',
            label: 'Maximum asset size',
            type: SettingDefinition::TYPE_NUMBER,
            default: DatabaseAssetStore::MAX_BYTES,
            section: 'media',
            description: 'Hard ceiling per asset. Inlined payloads must stay under the media_assets column limit.',
            validation: self::VALIDATION_UINT,
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
            validation: self::VALIDATION_UINT,
        ));
        $add(new SettingDefinition(
            key: 'SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES',
            label: 'Maximum remote media size',
            type: SettingDefinition::TYPE_NUMBER,
            default: 100 * 1024 * 1024,
            section: 'media',
            description: 'Larger responses are recorded as external rather than downloaded.',
            validation: self::VALIDATION_UINT,
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
            default: 'sendmail',
            group: 'mail',
            section: 'mail',
            transform: 'mail_driver',
            options: [
                ['value' => 'sendmail', 'label' => 'Sendmail — hand off to the local mail binary'],
                ['value' => 'smtp', 'label' => 'SMTP — deliver through a mail server'],
                ['value' => 'log', 'label' => 'Log — write messages to the log, send nothing'],
            ],
            description: 'How outbound mail is delivered. Sendmail is the default because it is what an unconfigured install has always done.',
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
            validation: self::VALIDATION_UINT,
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
            // No "None" here: `SystemMailer::buildSmtpDsn()` throws for
            // anything that is not `tls` or `ssl`, so an empty option put a
            // choice in the operator's hands that bricks every send once
            // taken — with a 200 confirming it was saved. The registry is what
            // the admin dropdown renders from, so it must not advertise a
            // value the application rejects.
            options: [
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
        $add(new SettingDefinition(
            key: 'SPORA_MERCURE_LEGACY_USER_TOPIC',
            label: 'Publish to the legacy user topic',
            type: SettingDefinition::TYPE_TOGGLE,
            default: false,
            section: 'security',
            description: 'Also publish updates to the pre-principals per-user topic. Only useful if something is still subscribed to it.',
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
            validation: self::VALIDATION_UINT,
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
            validation: self::VALIDATION_UINT,
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
