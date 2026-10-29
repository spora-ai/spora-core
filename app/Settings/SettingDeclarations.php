<?php

declare(strict_types=1);

namespace Spora\Settings;

use Spora\Core\Paths;
use Spora\Core\RequestOrigin;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\PluginCatalogService;

/**
 * The declared settings themselves, one method per registry section.
 *
 * Split out of {@see SettingsRegistry} because the declarations are the bulk
 * of that file and none of it is resolution logic: the registry asks this
 * class what exists, then answers questions about coercion, encoding and
 * which keys are boot-only. A setting is added by adding a definition to the
 * section it belongs to — there is no separate registration step to forget.
 *
 * The methods are public only so the registry can reach them. They belong to
 * no interface, and {@see SettingsRegistry::all()} stays the single way to
 * read the set.
 */
final class SettingDeclarations
{
    /**
     * Non-negative integer, for every duration, count, size and timeout here.
     * A named constant so the rule lives once instead of being restated
     * thirteen times — and so a change to it cannot miss a key.
     */
    public const VALIDATION_UINT = '/^\d+$/';

    public function __construct(private readonly Paths $paths) {}

    /**
     * @return array<string, SettingDefinition>
     */
    public function build(): array
    {
        $definitions = [];

        foreach ([
            $this->applicationSettings(),
            $this->accessSettings(),
            $this->timeoutSettings(),
            $this->workerSettings(),
            $this->pluginSettings(),
            $this->mediaSettings(),
            $this->mailSettings(),
            $this->realtimeSettings(),
            $this->databaseSettings(),
        ] as $group) {
            foreach ($group as $definition) {
                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return list<SettingDefinition>
     */
    public function applicationSettings(): array
    {
        $paths = $this->paths;

        return [
            // Application.
            new SettingDefinition(
                key: 'SPORA_APP_NAME',
                label: 'Application name',
                type: SettingDefinition::TYPE_TEXT,
                default: 'Spora',
                section: 'general',
                description: 'Shown in the browser tab and in outbound email.',
            ),
            new SettingDefinition(
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
            ),
            new SettingDefinition(
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
                description: 'File-only. The log level is what you reach for when the interface itself is broken, so it cannot be changed from here.',
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_LOG_PATH',
                label: 'Log file path',
                type: SettingDefinition::TYPE_TEXT,
                default: $paths->storage('spora.log'),
                section: 'general',
                description: 'A filesystem path, so it cannot live in the database it would be written from.',
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_DEFAULT_LANG',
                label: 'Default language',
                type: SettingDefinition::TYPE_SELECT,
                default: 'en',
                section: 'general',
                options: [
                    ['value' => 'en', 'label' => 'English'],
                    ['value' => 'de', 'label' => 'Deutsch'],
                ],
                description: 'Language for people who have not chosen one of their own. A user\'s own choice wins over this.',
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function accessSettings(): array
    {
        return [
            // Access control.
            new SettingDefinition(
                key: 'SPORA_ALLOW_REGISTRATION',
                label: 'Allow registration',
                type: SettingDefinition::TYPE_TOGGLE,
                default: true,
                section: 'access',
                description: 'When off, only existing accounts can sign in. Turn this off if signups should be invite-only.',
            ),
            new SettingDefinition(
                key: 'SPORA_ALLOW_GROUP_CREATION',
                label: 'Allow group creation',
                type: SettingDefinition::TYPE_TOGGLE,
                default: true,
                section: 'access',
                description: 'When on, any signed-in user may create a group. Turn it off to keep creation admin-only; editing and deleting stay admin-only either way.',
            ),
            new SettingDefinition(
                key: 'SPORA_NOTIFICATIONS_EMAIL_ENABLED',
                label: 'Email notifications',
                type: SettingDefinition::TYPE_TOGGLE,
                default: true,
                section: 'access',
                description: 'Master switch for notification email. Individual subscriptions still apply.',
            ),
            new SettingDefinition(
                key: 'SPORA_AGENT_API',
                label: 'Agent API',
                type: SettingDefinition::TYPE_TOGGLE,
                default: false,
                section: 'access',
                description: 'Mounts a per-agent HTTP API so external tools can start and continue chats with that agent. '
                    . 'Requires a server-side worker: in client-worker mode every endpoint answers 404. '
                    . 'Each agent has its own API keys, under the agent\'s Settings → API keys.',
            ),
            new SettingDefinition(
                key: 'SPORA_AGENT_API_RATE_LIMIT_MAX',
                label: 'Agent API rate limit',
                type: SettingDefinition::TYPE_NUMBER,
                default: 60,
                section: 'access',
                description: 'Requests per minute a single Agent API key may make. Exceeding it answers 429 with a Retry-After header.',
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function timeoutSettings(): array
    {
        return [
            // Timeouts.
            new SettingDefinition(
                key: 'SPORA_LLM_TIMEOUT',
                label: 'LLM request timeout',
                type: SettingDefinition::TYPE_NUMBER,
                default: 300,
                section: 'timeouts',
                description: 'Seconds to wait for a model response before giving up.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_TOOL_HTTP_TIMEOUT',
                label: 'Tool HTTP timeout',
                type: SettingDefinition::TYPE_NUMBER,
                default: 30,
                section: 'timeouts',
                description: 'Default seconds for outbound HTTP from tools. A tool that declares its own timeout overrides this.',
                validation: self::VALIDATION_UINT,
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function workerSettings(): array
    {
        return [
            // Worker.
            new SettingDefinition(
                key: 'SPORA_WORKER_RUNTIME_MODE',
                label: 'Worker runtime mode',
                type: SettingDefinition::TYPE_SELECT,
                default: 'server',
                section: 'worker',
                options: [
                    ['value' => 'server', 'label' => 'Server — bin/spora worker:run drains the queue'],
                    ['value' => 'client', 'label' => 'Client — browser SharedWorker, no daemon'],
                ],
                description: 'Where queued tasks are executed. File-only — a worker reads this at startup, so a change would not reach the daemon already running.',
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_TICK_LEASE_SECONDS',
                label: 'Tick lease',
                type: SettingDefinition::TYPE_NUMBER,
                default: 600,
                section: 'worker',
                description: 'Seconds the reaper waits past the last heartbeat before marking a run as failed.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_WORKER_STALE_MINUTES',
                label: 'Stale worker threshold',
                type: SettingDefinition::TYPE_NUMBER,
                default: 60,
                section: 'worker',
                description: 'Minutes without a heartbeat before a worker is considered dead.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_WORKER_CLIENT_HOUSEKEEPING_INTERVAL_SECONDS',
                label: 'Client housekeeping interval',
                type: SettingDefinition::TYPE_NUMBER,
                default: 300,
                section: 'worker',
                description: 'Seconds between housekeeping calls from the browser in client-worker mode. '
                    . 'A shorter interval notices due work sooner and costs more requests. '
                    . 'Never below 10 seconds: the endpoint allows 6 calls a minute, so a shorter interval is answered with 429 — '
                    . 'and an interval of 0 would switch the browser\'s housekeeping off entirely, '
                    . 'which means no due work runs in client-worker mode.',
                validation: self::VALIDATION_UINT,
                min: 10,
            ),
            new SettingDefinition(
                key: 'SPORA_MAX_WORKERS',
                label: 'Maximum concurrent workers',
                type: SettingDefinition::TYPE_NUMBER,
                default: 0,
                section: 'worker',
                description: 'Zero means no limit. File-only — a change needs a worker restart, which is exactly when the file is the right place for it.',
                validation: self::VALIDATION_UINT,
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_TOOLS_GROUP_CASCADE_ENABLED',
                label: 'Group tool-settings cascade',
                type: SettingDefinition::TYPE_TOGGLE,
                default: false,
                section: 'worker',
                description: 'When on, a value set on a group also applies to that group\'s members.',
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function pluginSettings(): array
    {
        return [
            // Plugins.
            new SettingDefinition(
                key: 'SPORA_PLUGIN_INSTALL_ENABLED',
                label: 'Allow plugin installation',
                type: SettingDefinition::TYPE_TOGGLE,
                default: false,
                section: 'plugins',
                description: 'Required for installing plugins from the catalog. Needs a reachable Composer binary. Turning this off does not uninstall anything.',
            ),
            new SettingDefinition(
                key: 'SPORA_PLUGIN_CATALOG_ENABLED',
                label: 'Plugin catalog',
                type: SettingDefinition::TYPE_TOGGLE,
                default: true,
                section: 'plugins',
                description: 'When off, Spora makes no outbound requests to browse the catalog.',
            ),
            new SettingDefinition(
                key: 'SPORA_PLUGIN_CATALOG_TTL',
                label: 'Catalog cache lifetime',
                type: SettingDefinition::TYPE_NUMBER,
                default: PluginCatalogService::DEFAULT_TTL_SECONDS,
                section: 'plugins',
                description: 'Seconds a catalog search stays cached on disk.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_COMPOSER_BINARY',
                label: 'Composer binary',
                type: SettingDefinition::TYPE_TEXT,
                default: 'composer',
                section: 'plugins',
                description: 'Name on PATH, or an absolute path to composer.phar, which is run through this PHP binary. On shared hosting there is usually no global composer, and the Docker image ships none — it is only present while building.',
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function mediaSettings(): array
    {
        return [
            // Asset storage.
            new SettingDefinition(
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
            ),
            new SettingDefinition(
                key: 'SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES',
                label: 'Auto threshold',
                type: SettingDefinition::TYPE_NUMBER,
                default: 1024 * 1024,
                section: 'media',
                description: 'In auto mode, payloads at or below this are inlined.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_ASSET_STORE_MAX_BYTES',
                label: 'Maximum asset size',
                type: SettingDefinition::TYPE_NUMBER,
                default: DatabaseAssetStore::MAX_BYTES,
                section: 'media',
                description: 'Hard ceiling per asset. Inlined payloads must stay under the media_assets column limit.',
                validation: self::VALIDATION_UINT,
            ),

            // Media archive.
            new SettingDefinition(
                key: 'SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL',
                label: 'Store remote media locally',
                type: SettingDefinition::TYPE_TOGGLE,
                default: true,
                section: 'media',
                description: 'When on, a URL passed to a tool is downloaded and stored. Turn it off to keep only the link.',
            ),
            new SettingDefinition(
                key: 'SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT',
                label: 'Media fetch timeout',
                type: SettingDefinition::TYPE_NUMBER,
                default: 30,
                section: 'media',
                description: 'Seconds allowed for the remote media fetch.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES',
                label: 'Maximum remote media size',
                type: SettingDefinition::TYPE_NUMBER,
                default: 100 * 1024 * 1024,
                section: 'media',
                description: 'Larger responses are recorded as external rather than downloaded.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_MEDIA_ARCHIVE_FFPROBE_ENABLED',
                label: 'Extract media duration',
                type: SettingDefinition::TYPE_TOGGLE,
                default: false,
                section: 'media',
                description: 'Uses ffprobe when it is on PATH. Slower to ingest.',
            ),
            new SettingDefinition(
                key: 'SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES',
                label: 'Allowed image types',
                type: SettingDefinition::TYPE_LIST,
                default: ['png', 'jpeg', 'webp'],
                section: 'media',
                transform: 'image_types',
                description: 'Image formats offered as attachments. SVG is always rejected and cannot be enabled here.',
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function mailSettings(): array
    {
        return [
            // Mail.
            new SettingDefinition(
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
            ),
            new SettingDefinition(
                key: 'SPORA_MAIL_HOST',
                label: 'SMTP host',
                type: SettingDefinition::TYPE_TEXT,
                default: 'localhost',
                group: 'mail',
                section: 'mail',
            ),
            new SettingDefinition(
                key: 'SPORA_MAIL_PORT',
                label: 'SMTP port',
                type: SettingDefinition::TYPE_NUMBER,
                default: 587,
                group: 'mail',
                section: 'mail',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_MAIL_USERNAME',
                label: 'SMTP username',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                group: 'mail',
                section: 'mail',
            ),
            new SettingDefinition(
                key: 'SPORA_MAIL_PASSWORD',
                label: 'SMTP password',
                type: SettingDefinition::TYPE_PASSWORD,
                default: '',
                group: 'mail',
                section: 'mail',
                secret: true,
            ),
            new SettingDefinition(
                key: 'SPORA_MAIL_FROM',
                label: 'From address',
                type: SettingDefinition::TYPE_TEXT,
                default: 'noreply@example.com',
                group: 'mail',
                section: 'mail',
                validation: '/^[^@\s]+@[^@\s]+\.[^@\s]+$/',
            ),
            new SettingDefinition(
                key: 'SPORA_MAIL_FROM_NAME',
                label: 'From name',
                type: SettingDefinition::TYPE_TEXT,
                default: 'Spora',
                group: 'mail',
                section: 'mail',
            ),
            new SettingDefinition(
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
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function realtimeSettings(): array
    {
        return [
            // Mercure.
            new SettingDefinition(
                key: 'SPORA_MERCURE_URL',
                label: 'Mercure hub URL',
                type: SettingDefinition::TYPE_TEXT,
                section: 'realtime',
                description: 'Live-update hub. Leave empty if you do not use live updates.',
            ),
            new SettingDefinition(
                key: 'SPORA_MERCURE_PUBLISH_URL',
                label: 'Mercure publish URL',
                type: SettingDefinition::TYPE_TEXT,
                section: 'realtime',
                description: 'Publisher endpoint, when it differs from the hub URL.',
            ),
            new SettingDefinition(
                key: 'SPORA_MERCURE_JWT_KEY',
                label: 'Mercure JWT key',
                type: SettingDefinition::TYPE_PASSWORD,
                section: 'realtime',
                secret: true,
                description: 'Shared secret used to sign Mercure update tokens.',
            ),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function databaseSettings(): array
    {
        $paths = $this->paths;

        return [
            // Database and keys — every one of these is read before the database
            // can be opened, which is why they are `bootOnly`.
            new SettingDefinition(
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
            ),
            new SettingDefinition(
                key: 'SPORA_DB_PATH',
                label: 'SQLite database file',
                type: SettingDefinition::TYPE_TEXT,
                default: $paths->storage('database.sqlite'),
                section: 'database',
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_SQLITE_BUSY_TIMEOUT',
                label: 'SQLite busy timeout',
                type: SettingDefinition::TYPE_NUMBER,
                section: 'database',
                bootOnly: true,
                description: 'Milliseconds to wait for a locked database before failing.',
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_DB_HOST',
                label: 'Database host',
                type: SettingDefinition::TYPE_TEXT,
                section: 'database',
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_DB_PORT',
                label: 'Database port',
                type: SettingDefinition::TYPE_NUMBER,
                section: 'database',
                bootOnly: true,
                validation: self::VALIDATION_UINT,
            ),
            new SettingDefinition(
                key: 'SPORA_DB_NAME',
                label: 'Database name',
                type: SettingDefinition::TYPE_TEXT,
                section: 'database',
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_DB_USER',
                label: 'Database user',
                type: SettingDefinition::TYPE_TEXT,
                section: 'database',
                bootOnly: true,
            ),
            new SettingDefinition(
                key: 'SPORA_DB_PASSWORD',
                label: 'Database password',
                type: SettingDefinition::TYPE_PASSWORD,
                section: 'database',
                bootOnly: true,
                secret: true,
            ),
            new SettingDefinition(
                key: 'SPORA_KEY_PATH',
                label: 'Secret key file',
                type: SettingDefinition::TYPE_TEXT,
                section: 'realtime',
                bootOnly: true,
                description: 'Absolute path to the 32-byte secret.key. Changing it makes every stored secret undecryptable.',
                secret: true,
            ),
            new SettingDefinition(
                key: 'SPORA_APP_URL',
                label: 'Application URL',
                type: SettingDefinition::TYPE_TEXT,
                default: RequestOrigin::detect(),
                section: 'realtime',
                bootOnly: true,
                description: 'Used in links in outbound mail. Detected from the request when not set.',
            ),
            new SettingDefinition(
                key: 'SPORA_APP_PREFIX',
                label: 'Application path prefix',
                type: SettingDefinition::TYPE_TEXT,
                default: RequestOrigin::detectWithPrefix()[1],
                section: 'realtime',
                bootOnly: true,
                description: 'Set when Spora is installed in a subdirectory, for example /spora.',
                transform: 'app_prefix',
            ),
        ];
    }
}
