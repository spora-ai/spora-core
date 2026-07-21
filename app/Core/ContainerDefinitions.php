<?php

declare(strict_types=1);

namespace Spora\Core;

use Delight\Auth\Auth as DelightAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateScanner;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Apps\AppRegistry;
use Spora\Apps\MemoriesApp;
use Spora\Apps\PluginsApp;
use Spora\Auth\AuthService;
use Spora\Console\Commands\AssetGcCommand;
use Spora\Console\Commands\MediaArchiveGcCommand;
use Spora\Console\Commands\MediaArchiveListCommand;
use Spora\Console\Commands\PluginInstallCommand;
use Spora\Console\Commands\PluginListCommand;
use Spora\Console\Commands\PluginUninstallCommand;
use Spora\Console\Commands\PluginUpdateCommand;
use Spora\Console\Commands\SeedCommand;
use Spora\Console\Commands\SetupCommand;
use Spora\Console\Commands\TaskRunCommand;
use Spora\Console\Commands\WorkerRunCommand;
use Spora\Core\Exceptions\BasePathNotDefinedException;
use Spora\Core\Exceptions\InvalidSecretKeyException;
use Spora\Core\Exceptions\MissingSecretKeyException;
use Spora\Core\Extension\PluginManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Extensions\AppLoader;
use Spora\Http\AgentController;
use Spora\Http\AgentMemoryController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentTemplateController;
use Spora\Http\AgentToolController;
use Spora\Http\AppsController;
use Spora\Http\AuthController;
use Spora\Http\ConfigController;
use Spora\Http\HealthController;
use Spora\Http\LLMConfigController;
use Spora\Http\MailConfigController;
use Spora\Http\MailTemplateController;
use Spora\Http\MediaAllowedTypesController;
use Spora\Http\MediaArchiveController;
use Spora\Http\MediaUploadController;
use Spora\Http\MemoryController;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\NotificationController;
use Spora\Http\PluginsController;
use Spora\Http\PromptTemplateController;
use Spora\Http\PublicMediaController;
use Spora\Http\ScheduledRunController;
use Spora\Http\SseController;
use Spora\Http\TaskController;
use Spora\Http\ToolController;
use Spora\Http\UserController;
use Spora\Http\UserPreferenceController;
use Spora\Http\UserProfileController;
use Spora\Models\MailTemplate;
use Spora\Plugins\PluginLoader;
use Spora\Security\CsrfTokenService;
use Spora\Services\AgentService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AssetStore;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\HandoverService;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\LLMConfigService;
use Spora\Services\LLMConfigServiceInterface;
use Spora\Services\LlmConfigValidator;
use Spora\Services\LocalAssetStore;
use Spora\Services\MailTemplateService;
use Spora\Services\MailTemplateServiceInterface;
use Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter;
use Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Spora\Services\MemoryService;
use Spora\Services\MemoryServiceInterface;
use Spora\Services\MercurePublisher;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\NotificationServiceInterface;
use Spora\Services\PluginCatalogService;
use Spora\Services\PluginMetadataExtractor;
use Spora\Services\PluginsService;
use Spora\Services\PromptTemplateService;
use Spora\Services\PromptTemplateServiceInterface;
use Spora\Services\ScheduledRunService;
use Spora\Services\ScheduledRunServiceInterface;
use Spora\Services\SystemMailer;
use Spora\Services\TaskService;
use Spora\Services\TaskServiceInterface;
use Spora\Services\ToolCallSerializer;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolIconResolver;
use Spora\Services\UserService;
use Spora\Services\UserServiceInterface;
use Spora\Tools\AgentMemoryTool;
use Spora\Tools\CalculatorTool;
use Spora\Tools\CurrentTimeTool;
use Spora\Tools\GlobalMemoryTool;
use Spora\Tools\HandoverTool;
use Spora\Tools\ReadUrlTool;
use Spora\Tools\UserInfoTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContainerDefinitions
{
    public static function all(): array
    {
        // Self-register the core media converters with the static discovery
        // list. Plugins add their own converters in their `register(ContainerBuilder)`
        // hook (see docs/07_plugins.md). The list is read by
        // MediaConverterRegistry at construction time.
        MediaConverterDiscovery::add(PdfToMarkdownConverter::class);
        MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);

        return array_merge(
            self::configDefinition(),
            self::coreServiceDefinitions(),
            self::llmDefinitions(),
            self::apiAuthControllerDefinitions(),
            self::apiResourceControllerDefinitions(),
            self::apiTaskControllerDefinitions(),
            self::adminControllerDefinitions(),
            self::toolDefinitions(),
            self::extensionDefinitions(),
            self::orchestratorDefinitions(),
            self::consoleCommandDefinitions(),
        );
    }

    private static function configDefinition(): array
    {
        return [
            'config' => static function (ContainerInterface $c): array {
                $paths = $c->get(Paths::class);
                $defaults = [
                    'db_driver'           => 'sqlite',
                    'db_path'             => $paths->storage('database.sqlite'),
                    'db_host'             => null,
                    'db_port'             => null,
                    'db_name'             => null,
                    'db_user'             => null,
                    'db_password'         => null,
                    'key_path'            => null,
                    'allow_registration'  => true,
                    'app_env'             => 'production',
                    'log_level'           => 'WARNING',
                    'log_path'            => $paths->storage('spora.log'),
                    // Sync mode when no env is set. The shipped spora/.env.example
                    // overrides this to `false` (queue mode) for operators who
                    // run a worker. The dual defaults are intentional — see
                    // env-vars.md in spora-docs: the safe fallback for env-less
                    // LAMP/FTP deploys is sync (no worker); the safe choice for
                    // .env-using deploys is queue (worker drains it).
                    'worker_mode'         => true,
                    'worker_stale_minutes' => 60,
                    'max_workers'         => 0,
                    'llm_timeout'         => 300,
                    'tool_http_timeout'   => 30,
                    'mercure_url'         => null,
                    'mercure_jwt_key'     => null,
                    'app_url'             => RequestOrigin::detect(),

                    'plugin_install_enabled' => false,

                    // Path/name of the composer executable PluginManager shells out to.
                    // 'composer' relies on the host's $PATH (typical for dev/CI). Shared-host
                    // operators can ship `bin/composer.phar` and override this with an absolute
                    // path; when the value ends in `.phar` PluginManager prepends PHP_BINARY.
                    'composer_binary'     => 'composer',

                    // Binary asset storage. Plugins produce images/audio/video via
                    // AssetStore::store() — the mode setting decides whether the
                    // payload ships inline (data: URL) or written to disk and
                    // served via GET /api/v1/assets/{token}. 'auto' picks per-call
                    // based on auto_threshold_bytes.
                    //   - mode:                 'auto' | 'data_url' | 'local'
                    //   - auto_threshold_bytes: payloads ≤ this become data URLs
                    //   - max_bytes:            hard ceiling per asset
                    'asset_store' => [
                        'mode'                 => 'auto',
                        'auto_threshold_bytes' => 1 * 1024 * 1024,
                        'max_bytes'            => 50 * 1024 * 1024,
                    ],

                    // Plugin catalog (Packagist browse) — enabled by default. The
                    // endpoint is registered even when off so the navbar item
                    // can decide to hide itself on a 404 instead of polling
                    // a separate config endpoint. Set to false to take the
                    // operator offline from external HTTP traffic.
                    'plugin_catalog_enabled' => true,

                    // Cache TTL (seconds) for the on-disk plugin-catalog cache.
                    // One file, one entry per query (keyed by a SHA-256 fingerprint
                    // of the query string); multiple queries share the storage path.
                    // 1 hour by default — Packagist search is not real-time.
                    'plugin_catalog_ttl' => PluginCatalogService::DEFAULT_TTL_SECONDS,

                    // Media Archive: optional ingest surface for plugins that
                    // produce binary media. When `promote_external = true`
                    // (default), URL inputs are fetched and stored locally via
                    // AssetStore so the operator's UI shows durable copies.
                    //   - promote_external:    when false, URL inputs are stored
                    //                          as `storage_mode = external` without
                    //                          fetching the body
                    //   - fetch_timeout_seconds: ceiling for the HEAD + GET path
                    //   - max_promote_bytes:    CDN responses larger than this are
                    //                           recorded as `external` (avoid filling
                    //                           disk with multi-GB videos)
                    //   - ffprobe_enabled:      when true (and ffprobe is on PATH),
                    //                           audio/video duration is extracted
                    'media_archive' => [
                        'promote_external'      => true,
                        'fetch_timeout_seconds' => 30,
                        'max_promote_bytes'     => 100 * 1024 * 1024,
                        'ffprobe_enabled'       => false,

                        // Image MIME types the upload UI offers as image-only
                        // attachments (PNG/JPEG/WebP). Operators can extend this
                        // via config.php (`'media_archive' => ['allowed_image_types' => [...]]`)
                        // or via `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES` env var
                        // (comma-separated). An empty list explicitly disables
                        // image uploads; missing key falls back to this default.
                        // GIF is opt-in (GPT-4o accepts non-animated GIF); SVG is
                        // excluded pending a sanitization pass.
                        'allowed_image_types'   => ['png', 'jpeg', 'webp'],
                    ],
                ];

                $configPath = $_ENV['SPORA_CONFIG_PATH'] ?? (getenv('SPORA_CONFIG_PATH') ?: $paths->config());
                $fileConfig = UserConfig::load($configPath);

                $envOverrides = self::collectEnvOverrides();
                // Deep merge for associative maps so env-overridden nested keys
                // (e.g. `media_archive.fetch_timeout_seconds`) reach consumers
                // that read `$config['media_archive']['fetch_timeout_seconds']`.
                // List arrays are replaced atomically — overriding
                // `['png','jpeg','webp']` with `['gif']` yields `['gif']`, not
                // a merged list.
                return self::mergeConfig($defaults, $fileConfig, $envOverrides);
            },
        ];
    }

    private static function collectEnvOverrides(): array
    {
        $env = static fn(string $k): ?string => $_ENV[$k] ?? (getenv($k) ?: null);

        $overrides = [];
        // `set` writes the value at the dot-path inside `$overrides`. A
        // key like `media_archive.allowed_image_types` lands at
        // `['media_archive' => ['allowed_image_types' => …]]` so the deep
        // merge in `mergeConfig()` keeps it next to its siblings. Each
        // call returns true if the value was actually set.
        $set = static function (string $dotPath, mixed $value) use (&$overrides): bool {
            if ($value === null) {
                return false;
            }
            $segments = explode('.', $dotPath);
            $cursor = & $overrides;
            foreach (array_slice($segments, 0, -1) as $segment) {
                if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }
                $cursor = & $cursor[$segment];
            }
            $cursor[end($segments)] = $value;
            return true;
        };
        $apply = static function (string $envVar, string $key, callable $cast) use ($env, $set): void {
            $value = $env($envVar);
            $set($key, $value === null ? null : $cast($value));
        };

        $apply('SPORA_DB_DRIVER', 'db_driver', static fn($v) => $v);
        $apply('SPORA_DB_HOST', 'db_host', static fn($v) => $v);
        $apply('SPORA_DB_PORT', 'db_port', static fn($v) => (int) $v);
        $apply('SPORA_DB_NAME', 'db_name', static fn($v) => $v);
        $apply('SPORA_DB_USER', 'db_user', static fn($v) => $v);
        $apply('SPORA_DB_PASSWORD', 'db_password', static fn($v) => $v);
        $apply('SPORA_SQLITE_BUSY_TIMEOUT', 'sqlite_busy_timeout', static fn($v) => (int) $v);
        $apply('SPORA_APP_ENV', 'app_env', static fn($v) => $v);
        $apply('SPORA_ALLOW_REGISTRATION', 'allow_registration', static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $apply('SPORA_LOG_LEVEL', 'log_level', static fn($v) => $v);
        $apply('SPORA_LOG_PATH', 'log_path', static fn($v) => $v);
        $apply('SPORA_SYNC_MODE', 'worker_mode', static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $apply('SPORA_WORKER_STALE_MINUTES', 'worker_stale_minutes', static fn($v) => (int) $v);
        $apply('SPORA_MAX_WORKERS', 'max_workers', static fn($v) => (int) $v);
        $apply('SPORA_LLM_TIMEOUT', 'llm_timeout', static fn($v) => (int) $v);
        $apply('SPORA_TOOL_HTTP_TIMEOUT', 'tool_http_timeout', static fn($v) => (int) $v);
        $apply('SPORA_MERCURE_URL', 'mercure_url', static fn($v) => $v);
        $apply('SPORA_MERCURE_JWT_KEY', 'mercure_jwt_key', static fn($v) => $v);
        $apply('SPORA_MERCURE_PUBLISH_URL', 'mercure_publish_url', static fn($v) => $v);
        $apply('SPORA_APP_URL', 'app_url', static fn($v) => $v);
        $apply('SPORA_COMPOSER_BINARY', 'composer_binary', static fn($v) => $v);
        $apply('SPORA_PLUGIN_INSTALL_ENABLED', 'plugin_install_enabled', static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $apply('SPORA_ASSET_STORE_MODE', 'asset_store.mode', static fn($v) => $v);
        $apply('SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES', 'asset_store.auto_threshold_bytes', static fn($v) => (int) $v);
        $apply('SPORA_ASSET_STORE_MAX_BYTES', 'asset_store.max_bytes', static fn($v) => (int) $v);
        $apply('SPORA_PLUGIN_CATALOG_ENABLED', 'plugin_catalog_enabled', static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $apply('SPORA_PLUGIN_CATALOG_TTL', 'plugin_catalog_ttl', static fn($v) => (int) $v);
        $apply('SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL', 'media_archive.promote_external', static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $apply('SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT', 'media_archive.fetch_timeout_seconds', static fn($v) => (int) $v);
        $apply('SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES', 'media_archive.max_promote_bytes', static fn($v) => (int) $v);
        $apply('SPORA_MEDIA_ARCHIVE_FFPROBE_ENABLED', 'media_archive.ffprobe_enabled', static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $set(
            'media_archive.allowed_image_types',
            self::parseImageTypesCsv($env('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES')),
        );

        $notifEmail = $env('SPORA_NOTIFICATIONS_EMAIL_ENABLED');
        if ($notifEmail !== null) {
            $set('notifications.email_enabled', filter_var($notifEmail, FILTER_VALIDATE_BOOLEAN));
        }

        return $overrides;
    }

    /**
     * Parse the `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES` env value.
     *
     * Returns null when the env var is unset (caller falls back to defaults).
     * Returns an empty array when the env var is set but empty — that means
     * the operator explicitly disabled image uploads. Both states must be
     * distinguishable from `['png','jpeg','webp']` (the built-in default).
     *
     * Whitespace is trimmed; tokens are lowercased; `jpg`/`jpeg` collapse to
     * `jpeg`. SVG variants are rejected explicitly. Order and duplicates are
     * collapsed to the first occurrence.
     *
     * @return list<string>|null
     */
    private static function parseImageTypesCsv(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $tokens = preg_split('/[\s,]+/', trim($raw));
        if ($tokens === false || implode('', $tokens) === '') {
            return [];
        }
        $normalized = [];
        $seen = [];
        foreach ($tokens as $token) {
            $t = strtolower(trim($token));
            if ($t === '') {
                continue;
            }
            $t = ltrim($t, '.');
            // SVG is excluded pending a sanitization pass.
            if ($t === 'svg' || $t === 'svg+xml') {
                continue;
            }
            $alias = $t === 'jpg' ? 'jpeg' : $t;
            if (!isset($seen[$alias])) {
                $seen[$alias] = true;
                $normalized[] = $alias;
            }
        }
        return $normalized;
    }

    /**
     * Deep-merge `$overrides` into `$base` for associative maps, but
     * replace list (numerically-indexed) arrays atomically.
     *
     * Precedence (last write wins):
     *   `$base` < `$fileConfig` < `$envOverrides`
     *
     * Without this helper, dotted env keys like
     * `media_archive.fetch_timeout_seconds` were stored as a literal
     * top-level key in the overrides array and never reached consumers
     * reading `$config['media_archive']['fetch_timeout_seconds']`.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $base, array ...$overrides): array
    {
        foreach ($overrides as $layer) {
            foreach ($layer as $key => $value) {
                if (
                    isset($base[$key])
                    && is_array($base[$key]) && is_array($value)
                    && array_is_list($base[$key]) === array_is_list($value)
                    && !array_is_list($value)
                ) {
                    $base[$key] = self::mergeConfig($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            }
        }
        return $base;
    }

    /**
     * Resolve BASE_PATH from the constant when defined, or throw a dedicated
     * exception so callers see a clear, actionable message rather than an
     * "undefined constant" fatal. Mirrors Kernel::resolveBasePath().
     */
    private static function resolveBasePath(): string
    {
        if (!defined('BASE_PATH')) {
            throw new BasePathNotDefinedException(
                'BASE_PATH is not defined. Add `define(\'BASE_PATH\', dirname(__FILE__, 2));` '
                . 'to your public/index.php (web entry) and bin/spora (CLI entry) '
                . 'before any Spora framework code runs.',
            );
        }
        return BASE_PATH;
    }

    private static function coreServiceDefinitions(): array
    {
        return [
            Paths::class => static function (): Paths {
                return new Paths(self::resolveBasePath());
            },

            SecurityManagerInterface::class => static fn(ContainerInterface $c): SecurityManager
                => self::buildSecurityManager($c),

            Database::class => static function (ContainerInterface $c): Database {
                $db = new Database(
                    $c->get('config'),
                    $c->get(PluginLoader::class),
                    $c->get(Paths::class),
                    $c->has(AppLoader::class) ? $c->get(AppLoader::class) : null,
                );
                $db->bootDatabaseConnectionOnly();
                return $db;
            },

            LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
                $config = $c->get('config');
                $levelStr = ucfirst(strtolower($config['log_level'] ?? 'warning'));
                $level = constant(Level::class . '::' . $levelStr);

                $logger = new MonologLogger('spora');

                $logPath = $config['log_path'] ?? $c->get(Paths::class)->storage('spora.log');
                $stream = ($logPath === 'stdout') ? 'php://stdout' : $logPath;
                $handler = new StreamHandler($stream, $level);

                $logger->pushHandler($handler);

                return $logger;
            },

            DelightAuth::class => static function (ContainerInterface $c): DelightAuth {
                $pdo = Capsule::connection()->getPdo();

                $config      = $c->get('config');
                $throttling  = ($config['app_env'] ?? 'production') !== 'testing';

                return new DelightAuth($pdo, null, null, $throttling);
            },

            AuthService::class => static function (ContainerInterface $c): AuthService {
                $authService = new AuthService($c->get(DelightAuth::class));
                $authService->setSystemMailer($c->get(SystemMailer::class));
                $authService->setAppUrl($c->get('config')['app_url'] ?? 'http://localhost');
                return $authService;
            },

            CsrfTokenService::class => static function (ContainerInterface $c): CsrfTokenService {
                return new CsrfTokenService(
                    $c->get(LoggerInterface::class),
                );
            },

            AuthMiddleware::class => static function (ContainerInterface $c): AuthMiddleware {
                return new AuthMiddleware(
                    $c->get(AuthService::class),
                );
            },

            CsrfMiddleware::class => static function (ContainerInterface $c): CsrfMiddleware {
                return new CsrfMiddleware(
                    $c->get(CsrfTokenService::class),
                    $c->get(LoggerInterface::class),
                );
            },

            HttpClientInterface::class => static function (): HttpClientInterface {
                return HttpClient::create();
            },

            ...self::assetStoreDefinitions(),

            // MediaArchive service stack — see app/Services/MediaArchive.
            // Config block lives under the `media_archive` key above.
            MimeSniffer::class => static fn(): MimeSniffer => new MimeSniffer(),

            RemoteMediaFetcher::class => static function (ContainerInterface $c): RemoteMediaFetcher {
                $cfg = $c->get('config')['media_archive'] ?? [];
                return new RemoteMediaFetcher(
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                    (int) ($cfg['fetch_timeout_seconds'] ?? 30),
                    (int) ($cfg['max_promote_bytes'] ?? (100 * 1024 * 1024)),
                );
            },

            MetadataExtractor::class => static function (ContainerInterface $c): MetadataExtractor {
                $cfg = $c->get('config')['media_archive'] ?? [];
                return new MetadataExtractor(
                    $c->get(LoggerInterface::class),
                    (bool) ($cfg['ffprobe_enabled'] ?? false),
                );
            },

            MediaArchiveUrlResolver::class => static function (ContainerInterface $c): MediaArchiveUrlResolver {
                $cfg = $c->get('config')['media_archive'] ?? [];
                return new MediaArchiveUrlResolver(
                    $c->get(RemoteMediaFetcher::class),
                    $c->get(MimeSniffer::class),
                    $c->get(LoggerInterface::class),
                    (bool) ($cfg['promote_external'] ?? true),
                    (int) ($cfg['max_promote_bytes'] ?? (100 * 1024 * 1024)),
                );
            },

            MediaArchiveService::class => static function (ContainerInterface $c): MediaArchiveService {
                return new MediaArchiveService(
                    $c->get(AssetStore::class),
                    $c->get(MediaArchiveUrlResolver::class),
                    $c->get(MimeSniffer::class),
                    $c->get(MetadataExtractor::class),
                    $c->get(MediaConverterRegistry::class),
                    $c->get(MediaIngestDecoder::class),
                );
            },

            MediaIngestDecoder::class => static fn(): MediaIngestDecoder => new MediaIngestDecoder(),

            TaskMediaCapabilityService::class => static function (ContainerInterface $c): TaskMediaCapabilityService {
                $factory = $c->has(DriverFactory::class) ? $c->get(DriverFactory::class) : null;
                return new TaskMediaCapabilityService($factory);
            },

            // Core converters self-register with the static discovery list
            // before the registry resolves them. Plugins add their own
            // converters in their `register(ContainerBuilder)` hook.
            PdfToMarkdownConverter::class => static function (ContainerInterface $c): PdfToMarkdownConverter {
                return new PdfToMarkdownConverter(
                    $c->get(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class),
                );
            },
            \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class => static fn(): \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser
                => new \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser(),
            PlainTextPassthroughConverter::class => static fn(): PlainTextPassthroughConverter
                => new PlainTextPassthroughConverter(),
            MediaConverterRegistry::class => static fn(ContainerInterface $c): MediaConverterRegistry
                => new MediaConverterRegistry($c),
            MediaAllowedTypesService::class => static fn(ContainerInterface $c): MediaAllowedTypesService
                => new MediaAllowedTypesService(
                    $c->get(MediaConverterRegistry::class),
                    $c->get(DriverFactory::class),
                    $c->get('config')['media_archive']['allowed_image_types'] ?? null,
                ),

            DriverFactory::class => static function (ContainerInterface $c): DriverFactory {
                return new DriverFactory(
                    $c->get(LoggerInterface::class),
                    $c->get(LLMConfigServiceInterface::class),
                    (int) ($c->get('config')['llm_timeout'] ?? 300),
                );
            },

            ToolConfigService::class => static function (ContainerInterface $c): ToolConfigService {
                return new ToolConfigService(
                    $c->get(SecurityManagerInterface::class),
                    $c->get(LoggerInterface::class),
                    array_values(array_unique(array_merge(
                        $c->get('tool_classes'),
                        $c->get(PluginLoader::class)->toolClasses(),
                    ))),
                );
            },

            ToolIconResolver::class => static function (ContainerInterface $c): ToolIconResolver {
                return new ToolIconResolver(
                    new ToolConfigNameResolver(
                        $c->get(LoggerInterface::class),
                        array_values(array_unique(array_merge(
                            $c->get('tool_classes'),
                            $c->get(PluginLoader::class)->toolClasses(),
                        ))),
                    ),
                    $c->get(PluginLoader::class),
                );
            },
        ];
    }

    /**
     * Definitions for the selectable binary asset storage strategies.
     *
     * @return array<string, callable>
     */
    private static function assetStoreDefinitions(): array
    {
        return [
            DataUrlAssetStore::class => static function (ContainerInterface $c): DataUrlAssetStore {
                $max = (int) ($c->get('config')['asset_store']['max_bytes'] ?? 50 * 1024 * 1024);
                return new DataUrlAssetStore($max);
            },

            LocalAssetStore::class => static function (ContainerInterface $c): LocalAssetStore {
                $max = (int) ($c->get('config')['asset_store']['max_bytes'] ?? 50 * 1024 * 1024);
                return new LocalAssetStore(
                    $c->get(Paths::class),
                    $c->get(SecurityManagerInterface::class),
                    $max,
                );
            },

            AssetStore::class => static function (ContainerInterface $c): AssetStore {
                $cfg  = $c->get('config')['asset_store'] ?? [];
                $mode = is_string($cfg['mode'] ?? null) ? $cfg['mode'] : 'auto';
                return match ($mode) {
                    'local'    => $c->get(LocalAssetStore::class),
                    'data_url' => $c->get(DatabaseAssetStore::class),
                    'auto'     => new AutoAssetStore(
                        $c->get(DatabaseAssetStore::class),
                        $c->get(LocalAssetStore::class),
                        (int) ($cfg['auto_threshold_bytes'] ?? 1_048_576),
                    ),
                    default    => throw new InvalidArgumentException(
                        "Unknown asset_store.mode: {$mode}",
                    ),
                };
            },

            DatabaseAssetStore::class => static function (ContainerInterface $c): DatabaseAssetStore {
                $max = (int) ($c->get('config')['asset_store']['max_bytes'] ?? 64 * 1024);
                return new DatabaseAssetStore($max);
            },
        ];
    }

    private static function buildSecurityManager(ContainerInterface $c): SecurityManager
    {
        $envKey = $_ENV['SPORA_SECRET_KEY'] ?? getenv('SPORA_SECRET_KEY') ?: null;
        if ($envKey !== null) {
            $decoded = base64_decode($envKey, strict: true);
            if ($decoded === false) {
                throw new InvalidSecretKeyException(
                    'SPORA_SECRET_KEY is not valid base64. Regenerate with: base64_encode(random_bytes(32))',
                );
            }
            return new SecurityManager($decoded);
        }

        $path = self::resolveKeyPath($c);
        if ($path === null) {
            throw new MissingSecretKeyException(
                'No secret key configured. Set SPORA_SECRET_KEY (base64 32 bytes) or SPORA_KEY_PATH, '
                . 'or run `php bin/spora spora:install` (or `db:seed`) to auto-generate '
                . 'storage/secret.key. Looked for: ' . $c->get(Paths::class)->storage('secret.key') . '.',
            );
        }

        return new SecurityManager($path);
    }

    private static function resolveKeyPath(ContainerInterface $c): ?string
    {
        $envKeyPath = $_ENV['SPORA_KEY_PATH'] ?? getenv('SPORA_KEY_PATH') ?: null;
        if ($envKeyPath !== null) {
            return $envKeyPath;
        }

        $configKeyPath = ($c->get('config'))['key_path'] ?? null;
        if ($configKeyPath !== null) {
            return (string) $configKeyPath;
        }

        // Conventional fallback; SecretKeyInstaller writes here on `spora:install`.
        $conventional = $c->get(Paths::class)->storage('secret.key');
        return is_file($conventional) ? $conventional : null;
    }

    // Gates the Web UI plugin install endpoints (docs/20_plugin_install_api.md).
    // CLI plugin commands are not gated — leave this off if `composer` isn't on $PATH.
    private static function resolvePluginInstallEnabled(ContainerInterface $c): bool
    {
        return (bool) ($c->get('config')['plugin_install_enabled'] ?? false);
    }

    private static function llmDefinitions(): array
    {
        return [
            'llm_driver_classes' => [
                OpenAICompatibleDriver::class,
                AnthropicCompatibleDriver::class,
            ],

            // Plugin and App drivers are merged into this separate entry at
            // container-build time. Kept distinct from `llm_driver_classes`
            // so that the static list remains inspectable in tests and so
            // LLMConfigService can opt into the merged view via constructor
            // injection without rewriting the core list contract.
            'llm_driver_classes_merged' => static fn(ContainerInterface $c): array => array_values(array_unique(array_merge(
                $c->get('llm_driver_classes'),
                array_values($c->get(PluginLoader::class)->drivers()),
                $c->has(AppLoader::class)
                    ? array_values($c->get(AppLoader::class)->getApp()?->drivers() ?? [])
                    : [],
            ))),

            'app_apps' => [
                MemoriesApp::class,
                PluginsApp::class,
            ],

            AppRegistry::class => static function (ContainerInterface $c): AppRegistry {
                $registry = new AppRegistry();
                $appContributedApps = $c->has(AppLoader::class)
                    ? ($c->get(AppLoader::class)->getApp()?->apps() ?? [])
                    : [];
                $pluginContributedApps = $c->has(PluginLoader::class)
                    ? $c->get(PluginLoader::class)->appClasses()
                    : [];
                foreach (array_merge($c->get('app_apps'), $appContributedApps, $pluginContributedApps) as $appClass) {
                    $registry->register($appClass);
                }
                return $registry;
            },

            'tool_classes' => [
                CurrentTimeTool::class,
                CalculatorTool::class,
                AgentMemoryTool::class,
                GlobalMemoryTool::class,
                ReadUrlTool::class,
                UserInfoTool::class,
                HandoverTool::class,
            ],

            LLMConfigService::class => static function (ContainerInterface $c): LLMConfigService {
                return new LLMConfigService(
                    $c->get(SecurityManagerInterface::class),
                    $c->get('llm_driver_classes_merged'),
                );
            },

            LLMConfigServiceInterface::class => static function (ContainerInterface $c): LLMConfigServiceInterface {
                return $c->get(LLMConfigService::class);
            },

            AgentServiceInterface::class => static function (ContainerInterface $c): AgentServiceInterface {
                return new AgentService(
                    $c->get(ToolConfigService::class),
                    $c->get(LLMConfigService::class),
                    $c->get(ToolIconResolver::class),
                );
            },

            UserServiceInterface::class => static fn(): UserServiceInterface => new UserService(),
        ];
    }

    private static function apiAuthControllerDefinitions(): array
    {
        return [
            AuthController::class => static function (ContainerInterface $c): AuthController {
                return new AuthController(
                    $c->get(AuthService::class),
                    $c->get(CsrfTokenService::class),
                    $c->get(AuthValidator::class),
                    $c->get(AuthWorkflow::class),
                    $c->get('config'),
                );
            },

            AuthWorkflow::class => static function (ContainerInterface $c): AuthWorkflow {
                return new AuthWorkflow(
                    $c->get(AuthService::class),
                    $c->get(UserServiceInterface::class),
                    $c->get(CsrfTokenService::class),
                    $c->get(AuthValidator::class),
                );
            },

            AuthValidator::class => static fn(): AuthValidator => new AuthValidator(),

            LLMConfigController::class => static function (ContainerInterface $c): LLMConfigController {
                return new LLMConfigController(
                    $c->get(AuthService::class),
                    $c->get(LLMConfigServiceInterface::class),
                    $c->get(LlmConfigValidator::class),
                );
            },

            LlmConfigValidator::class => static function (ContainerInterface $c): LlmConfigValidator {
                return new LlmConfigValidator(
                    $c->get(LLMConfigServiceInterface::class),
                );
            },

            UserPreferenceController::class => static function (ContainerInterface $c): UserPreferenceController {
                return new UserPreferenceController(
                    $c->get(AuthService::class),
                    $c->get(LLMConfigServiceInterface::class),
                );
            },

            ConfigController::class => static function (ContainerInterface $c): ConfigController {
                return new ConfigController(
                    $c->get('config'),
                );
            },

            UserProfileController::class => static function (ContainerInterface $c): UserProfileController {
                return new UserProfileController(
                    $c->get(AuthService::class),
                    $c->get(UserServiceInterface::class),
                );
            },
        ];
    }

    private static function apiResourceControllerDefinitions(): array
    {
        return [
            AppsController::class => static function (ContainerInterface $c): AppsController {
                return new AppsController(
                    $c->get(AppRegistry::class),
                    $c->get(PluginLoader::class),
                );
            },

            MemoryController::class => static function (ContainerInterface $c): MemoryController {
                return new MemoryController(
                    $c->get(AuthService::class),
                    $c->get(MemoryServiceInterface::class),
                );
            },

            AgentMemoryController::class => static function (ContainerInterface $c): AgentMemoryController {
                return new AgentMemoryController(
                    $c->get(AuthService::class),
                    $c->get(MemoryServiceInterface::class),
                );
            },

            PluginMetadataExtractor::class => static fn(): PluginMetadataExtractor => new PluginMetadataExtractor(),

            PluginsService::class => static function (ContainerInterface $c): PluginsService {
                return new PluginsService(
                    $c->get(PluginLoader::class),
                    $c->get(PluginMetadataExtractor::class),
                );
            },

            PluginCatalogService::class => static function (ContainerInterface $c): PluginCatalogService {
                return new PluginCatalogService(
                    $c->get(HttpClientInterface::class),
                    $c->get(Paths::class),
                    (int) ($c->get('config')['plugin_catalog_ttl'] ?? PluginCatalogService::DEFAULT_TTL_SECONDS),
                );
            },

            PluginsController::class => static function (ContainerInterface $c): PluginsController {
                $config = $c->get('config');
                $enabled = (bool) ($config['plugin_catalog_enabled'] ?? true);

                return new PluginsController(
                    $c->get(PluginsService::class),
                    $c->get(PluginManager::class),
                    self::resolvePluginInstallEnabled($c),
                    $enabled ? $c->get(PluginCatalogService::class) : null,
                    $enabled,
                );
            },

            AgentController::class => static function (ContainerInterface $c): AgentController {
                return new AgentController(
                    $c->get(AuthService::class),
                    $c->get(AgentServiceInterface::class),
                    $c->get(DriverFactory::class),
                    $c->get(ToolIconResolver::class),
                );
            },

            AgentToolController::class => static function (ContainerInterface $c): AgentToolController {
                return new AgentToolController(
                    $c->get(AuthService::class),
                    $c->get(AgentServiceInterface::class),
                    $c->get(ToolConfigService::class),
                );
            },

            AgentOverrideController::class => static function (ContainerInterface $c): AgentOverrideController {
                return new AgentOverrideController(
                    $c->get(AuthService::class),
                    $c->get(AgentServiceInterface::class),
                    $c->get(ToolConfigService::class),
                );
            },

            HealthController::class => static fn(): HealthController => new HealthController(),

            ToolController::class => static function (ContainerInterface $c): ToolController {
                return new ToolController(
                    $c->get(AuthService::class),
                    $c->get(ToolConfigService::class),
                    array_values(array_unique(array_merge(
                        $c->get('tool_classes'),
                        $c->get(PluginLoader::class)->toolClasses(),
                    ))),
                    $c->get(ToolIconResolver::class),
                );
            },

            MediaArchiveController::class => static function (ContainerInterface $c): MediaArchiveController {
                return new MediaArchiveController(
                    $c->get(MediaArchiveService::class),
                    $c->get(AuthService::class),
                );
            },

            MediaUploadController::class => static function (ContainerInterface $c): MediaUploadController {
                return new MediaUploadController(
                    $c->get(MediaArchiveService::class),
                    $c->get(MediaAllowedTypesService::class),
                    $c->get(AuthService::class),
                    $c->get(MimeSniffer::class),
                );
            },

            MediaAllowedTypesController::class => static function (ContainerInterface $c): MediaAllowedTypesController {
                return new MediaAllowedTypesController(
                    $c->get(MediaAllowedTypesService::class),
                );
            },

            PublicMediaController::class => static function (ContainerInterface $c): PublicMediaController {
                return new PublicMediaController(
                    $c->get(DatabaseAssetStore::class),
                    $c->get(LocalAssetStore::class),
                );
            },
        ];
    }

    private static function apiTaskControllerDefinitions(): array
    {
        return [
            TaskController::class => static function (ContainerInterface $c): TaskController {
                return new TaskController(
                    $c->get(AuthService::class),
                    $c->get(TaskServiceInterface::class),
                    $c->get(TaskMediaCapabilityService::class),
                );
            },

            TaskServiceInterface::class => static function (ContainerInterface $c): TaskServiceInterface {
                return new TaskService(
                    $c->get(OrchestratorInterface::class),
                    $c->get(MercurePublisherInterface::class),
                    $c->get(ToolCallSerializer::class),
                );
            },

            AgentTemplateController::class => static function (ContainerInterface $c): AgentTemplateController {
                return new AgentTemplateController(
                    $c->get(AuthService::class),
                    $c->get(AgentTemplateScanner::class),
                    $c->get(AgentTemplateValidator::class),
                    $c->get(AgentTemplateImporter::class),
                    $c->get(AgentTemplateExporter::class),
                    $c->get(AgentServiceInterface::class),
                );
            },

            PromptTemplateController::class => static function (ContainerInterface $c): PromptTemplateController {
                return new PromptTemplateController(
                    $c->get(AuthService::class),
                    $c->get(PromptTemplateServiceInterface::class),
                );
            },

            NotificationController::class => static function (ContainerInterface $c): NotificationController {
                return new NotificationController(
                    $c->get(AuthService::class),
                    $c->get(NotificationServiceInterface::class),
                );
            },

            SseController::class => static function (ContainerInterface $c): SseController {
                $config = $c->get('config');
                return new SseController(
                    $c->get(AuthService::class),
                    $config['mercure_publish_url'] ?? null,
                    $config['mercure_jwt_key'] ?? null,
                    '/.well-known/mercure',
                );
            },
        ];
    }

    private static function adminControllerDefinitions(): array
    {
        return [
            AdminMiddleware::class => static function (ContainerInterface $c): AdminMiddleware {
                return new AdminMiddleware(
                    $c->get(AuthService::class),
                );
            },

            UserController::class => static function (ContainerInterface $c): UserController {
                return new UserController(
                    $c->get(AuthService::class),
                    $c->get(UserServiceInterface::class),
                );
            },

            MailConfigController::class => static function (ContainerInterface $c): MailConfigController {
                return new MailConfigController(
                    $c->get(AuthService::class),
                    $c->get(SystemMailer::class),
                    $c->get('config'),
                );
            },

            MailTemplateController::class => static function (ContainerInterface $c): MailTemplateController {
                return new MailTemplateController(
                    $c->get(MailTemplateServiceInterface::class),
                );
            },

            ScheduledRunController::class => static function (ContainerInterface $c): ScheduledRunController {
                return new ScheduledRunController(
                    $c->get(AuthService::class),
                    $c->get(ScheduledRunServiceInterface::class),
                );
            },
        ];
    }

    private static function toolDefinitions(): array
    {
        return [
            'tool_instances' => static function (ContainerInterface $c): array {
                $appToolClasses = $c->has(AppLoader::class)
                    ? ($c->get(AppLoader::class)->getApp()?->tools() ?? [])
                    : [];
                $classes = array_values(array_unique(array_merge(
                    $c->get('tool_classes'),
                    $c->get(PluginLoader::class)->toolClasses(),
                    $appToolClasses,
                )));
                return array_combine($classes, array_map(
                    fn(string $toolClass) => $c->get($toolClass),
                    $classes,
                ));
            },

            ToolCallSerializer::class => static function (ContainerInterface $c): ToolCallSerializer {
                return new ToolCallSerializer(
                    toolInstances: $c->get('tool_instances'),
                );
            },

            CurrentTimeTool::class => static fn(): CurrentTimeTool => new CurrentTimeTool(),
            CalculatorTool::class => static fn(): CalculatorTool => new CalculatorTool(),
            AgentMemoryTool::class => static fn(): AgentMemoryTool => new AgentMemoryTool(),
            GlobalMemoryTool::class => static fn(): GlobalMemoryTool => new GlobalMemoryTool(),

            ReadUrlTool::class => static function (ContainerInterface $c): ReadUrlTool {
                return new ReadUrlTool(
                    $c->get(HttpClientInterface::class),
                    $c->get(ToolConfigService::class),
                    $c->get(LoggerInterface::class),
                    $c->get(MediaConverterRegistry::class),
                );
            },

            UserInfoTool::class => static fn(): UserInfoTool => new UserInfoTool(),

            HandoverTool::class => static function (ContainerInterface $c): HandoverTool {
                return new HandoverTool(
                    $c->get(HandoverServiceInterface::class),
                    $c->get(ToolConfigService::class),
                );
            },

            HandoverServiceInterface::class => static function (ContainerInterface $c): HandoverServiceInterface {
                // Closure defers OrchestratorInterface resolution until HandoverService::handover()
                // is called. Direct injection would create a cycle: Orchestrator → tool_instances
                // → HandoverTool → HandoverService → Orchestrator. Same pattern as SeedCommand.
                return new HandoverService(
                    static fn(): OrchestratorInterface => $c->get(OrchestratorInterface::class),
                );
            },
        ];
    }

    private static function extensionDefinitions(): array
    {
        return [
            PluginManager::class => static function (ContainerInterface $c): PluginManager {
                // The production closure builds a Symfony Process with the argv, cwd,
                // and a 120s timeout. Tests substitute a fake via the same closure
                // seam in PluginManager's constructor.
                $processFactory = static function (array $argv, string $cwd): object {
                    $process = new Process($argv, $cwd);
                    $process->setTimeout(PluginManager::TIMEOUT_SECONDS);
                    return $process;
                };

                $config = $c->get('config');
                $composerBinary = is_string($config['composer_binary'] ?? null) && $config['composer_binary'] !== ''
                    ? $config['composer_binary']
                    : 'composer';

                return new PluginManager(
                    $c->get(LoggerInterface::class),
                    $processFactory,
                    $c->get(Paths::class),
                    $composerBinary,
                );
            },
        ];
    }

    private static function orchestratorDefinitions(): array
    {
        return [
            OrchestratorInterface::class => static function (ContainerInterface $c): OrchestratorInterface {
                return new Orchestrator(
                    $c->get(DriverFactory::class),
                    new OrchestratorConfig(
                        llmConfigService: $c->get(LLMConfigService::class),
                        toolInstances: $c->get('tool_instances'),
                        logger: $c->get(LoggerInterface::class),
                        workerMode: ($c->get('config')['worker_mode'] ?? true) ? WorkerMode::Sync : WorkerMode::Worker,
                        notificationService: $c->get(NotificationService::class),
                        pluginLoader: $c->get(PluginLoader::class),
                        mercure: $c->get(MercurePublisherInterface::class),
                        toolConfigService: $c->get(ToolConfigService::class),
                        toolCallSerializer: $c->get(ToolCallSerializer::class),
                    ),
                );
            },

            MercurePublisherInterface::class => static function (ContainerInterface $c): MercurePublisherInterface {
                $config   = $c->get('config');
                $hubUrl   = $config['mercure_publish_url'] ?? $config['mercure_url'] ?? null;
                $jwtKey   = $config['mercure_jwt_key'] ?? null;
                $client   = $c->get(HttpClientInterface::class);

                return new MercurePublisher($client, $hubUrl, $jwtKey, $c->get(LoggerInterface::class));
            },

            NotificationService::class => static function (ContainerInterface $c): NotificationService {
                return new NotificationService(
                    $c->get(MercurePublisherInterface::class),
                    $c->get(SystemMailer::class),
                    $c->get('config'),
                );
            },

            NotificationServiceInterface::class => static function (ContainerInterface $c): NotificationServiceInterface {
                return $c->get(NotificationService::class);
            },

            SystemMailer::class => static function (ContainerInterface $c): SystemMailer {
                return new SystemMailer(
                    $c->get('config'),
                    $c->get(LoggerInterface::class),
                );
            },

            // PluginLoader is constructed eagerly in Kernel and added to the
            // builder there. The AppRegistry factory above consumes it via
            // `$c->get(PluginLoader::class)->appClasses()`.

            AgentTemplateScanner::class => static function (ContainerInterface $c): AgentTemplateScanner {
                $pluginLoader = $c->get(PluginLoader::class);
                $paths = $c->get(Paths::class);

                $appPaths = $c->has(AppLoader::class)
                    ? ($c->get(AppLoader::class)->getApp()?->agentTemplatePaths() ?? [])
                    : [];

                $directories = array_merge(
                    $paths->agentTemplatesPaths(),
                    $pluginLoader->agentTemplatePaths(),
                    $appPaths,
                );

                return new AgentTemplateScanner($directories);
            },

            AgentTemplateValidator::class => static fn(): AgentTemplateValidator => new AgentTemplateValidator(),

            AgentTemplateImporter::class => static function (ContainerInterface $c): AgentTemplateImporter {
                return new AgentTemplateImporter(
                    $c->get(ToolConfigService::class),
                    $c->get(PluginLoader::class),
                    $c->get(Paths::class),
                );
            },

            AgentTemplateExporter::class => static fn(ContainerInterface $c): AgentTemplateExporter => new AgentTemplateExporter(
                $c->get(PluginLoader::class),
            ),

            MemoryServiceInterface::class => static fn(): MemoryServiceInterface => new MemoryService(),
            MailTemplateServiceInterface::class => static fn(): MailTemplateServiceInterface => new MailTemplateService(),
            PromptTemplateServiceInterface::class => static fn(): PromptTemplateServiceInterface => new PromptTemplateService(),
            EmailTemplateLoader::class => static function (ContainerInterface $c): EmailTemplateLoader {
                return new EmailTemplateLoader($c->get(Paths::class));
            },
            ScheduledRunServiceInterface::class => static function (ContainerInterface $c): ScheduledRunServiceInterface {
                return new ScheduledRunService(
                    $c->get(OrchestratorInterface::class),
                    $c->get(MercurePublisherInterface::class),
                );
            },
            MailTemplate::class => static fn(): MailTemplate => new MailTemplate(),
        ];
    }

    private static function consoleCommandDefinitions(): array
    {
        return [
            SetupCommand::class => static function (ContainerInterface $c): SetupCommand {
                return new SetupCommand(
                    $c->get(Database::class),
                    $c->get(DatabaseSchemaInstaller::class),
                    $c->get(AuthService::class),
                    $c->get(EmailTemplateLoader::class),
                    $c->get(AgentTemplateImporter::class),
                );
            },

            SeedCommand::class => static function (ContainerInterface $c): SeedCommand {
                return new SeedCommand(
                    $c->get(Database::class),
                    static fn(): AuthService => $c->get(AuthService::class),
                    $c->get(EmailTemplateLoader::class),
                    $c->get(AgentTemplateImporter::class),
                    $c->get(Paths::class),
                );
            },

            WorkerRunCommand::class => static function (ContainerInterface $c): WorkerRunCommand {
                return new WorkerRunCommand(
                    $c->get(Database::class),
                    $c->get(OrchestratorInterface::class),
                    $c->get(LoggerInterface::class),
                    $c,
                    $c->get(MercurePublisherInterface::class),
                    $c->get(NotificationService::class),
                    $c->get(Paths::class),
                );
            },

            TaskRunCommand::class => static function (ContainerInterface $c): TaskRunCommand {
                return new TaskRunCommand(
                    $c->get(Database::class),
                    $c,
                    $c->get(MercurePublisherInterface::class),
                );
            },

            PluginInstallCommand::class => static function (ContainerInterface $c): PluginInstallCommand {
                return new PluginInstallCommand(
                    $c->get(PluginManager::class),
                );
            },

            PluginUninstallCommand::class => static function (ContainerInterface $c): PluginUninstallCommand {
                return new PluginUninstallCommand(
                    $c->get(PluginManager::class),
                );
            },

            PluginListCommand::class => static function (ContainerInterface $c): PluginListCommand {
                return new PluginListCommand(
                    $c->get(PluginManager::class),
                );
            },

            PluginUpdateCommand::class => static function (ContainerInterface $c): PluginUpdateCommand {
                return new PluginUpdateCommand(
                    $c->get(PluginManager::class),
                );
            },

            AssetGcCommand::class => static function (ContainerInterface $c): AssetGcCommand {
                return new AssetGcCommand(
                    $c->get(Paths::class),
                );
            },

            MediaArchiveListCommand::class => static function (ContainerInterface $c): MediaArchiveListCommand {
                return new MediaArchiveListCommand(
                    $c->get(MediaArchiveService::class),
                );
            },

            MediaArchiveGcCommand::class => static function (ContainerInterface $c): MediaArchiveGcCommand {
                return new MediaArchiveGcCommand(
                    $c->get(MediaArchiveService::class),
                    $c->get(Paths::class),
                );
            },
        ];
    }
}
