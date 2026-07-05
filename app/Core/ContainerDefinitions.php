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
use Spora\Apps\AppRegistry;
use Spora\Apps\MemoriesApp;
use Spora\Apps\PluginsApp;
use Spora\Auth\AuthService;
use Spora\Console\Commands\AssetGcCommand;
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
use Spora\Http\AgentToolController;
use Spora\Http\AppsController;
use Spora\Http\AuthController;
use Spora\Http\ConfigController;
use Spora\Http\HealthController;
use Spora\Http\LLMConfigController;
use Spora\Http\MailConfigController;
use Spora\Http\MailTemplateController;
use Spora\Http\MemoryController;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\NotificationController;
use Spora\Http\PluginsController;
use Spora\Http\PromptTemplateController;
use Spora\Http\RecipeController;
use Spora\Http\ScheduledRunController;
use Spora\Http\SseController;
use Spora\Http\TaskController;
use Spora\Http\ToolController;
use Spora\Http\UserController;
use Spora\Http\UserPreferenceController;
use Spora\Http\UserProfileController;
use Spora\Models\MailTemplate;
use Spora\Plugins\PluginLoader;
use Spora\Recipes\RecipeScanner;
use Spora\Security\CsrfTokenService;
use Spora\Services\AgentService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AssetStore;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\AutoAssetStore;
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
use Spora\Services\ToolConfigService;
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
                    'worker_mode'         => true,
                    'worker_stale_minutes' => 60,
                    'max_workers'         => 0,
                    'llm_timeout'         => 300,
                    'tool_http_timeout'   => 30,
                    'mercure_url'         => null,
                    'mercure_jwt_key'     => null,
                    'app_url'             => RequestOrigin::detect(),

                    // Plugin directories scanned by PluginLoader. The in-repo BASE_PATH/plugins
                    // is always appended; this list holds any additional external paths
                    // (e.g. sibling git checkouts of community plugins).
                    'plugins_paths'       => [],

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
                    // One file keyed by hash('sha256', $query); multiple queries share the
                    // storage path. 1 hour by default — Packagist search is
                    // not real-time.
                    'plugin_catalog_ttl' => PluginCatalogService::DEFAULT_TTL_SECONDS,
                ];

                $configPath = $_ENV['SPORA_CONFIG_PATH'] ?? (getenv('SPORA_CONFIG_PATH') ?: $paths->config());
                $fileConfig = UserConfig::load($configPath);

                $envOverrides = self::collectEnvOverrides();
                return array_merge($defaults, $fileConfig, $envOverrides);
            },
        ];
    }

    private static function collectEnvOverrides(): array
    {
        $env = static fn(string $k): ?string => $_ENV[$k] ?? (getenv($k) ?: null);

        $overrides = [];
        $apply = static function (string $envVar, string $key, callable $cast) use ($env, &$overrides): void {
            $value = $env($envVar);
            if ($value !== null) {
                $overrides[$key] = $cast($value);
            }
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
        $apply('SPORA_PLUGINS_PATHS', 'plugins_paths', static function (string $v): array {
            // Comma-separated absolute paths. Whitespace trimmed, empties dropped.
            $parts = array_filter(
                array_map('trim', explode(',', $v)),
                static fn(string $p): bool => $p !== '',
            );
            return array_values($parts);
        });
        $apply('SPORA_COMPOSER_BINARY', 'composer_binary', static fn($v) => $v);
        $apply('SPORA_ASSET_STORE_MODE', 'asset_store.mode', static fn($v) => $v);
        $apply('SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES', 'asset_store.auto_threshold_bytes', static fn($v) => (int) $v);
        $apply('SPORA_ASSET_STORE_MAX_BYTES', 'asset_store.max_bytes', static fn($v) => (int) $v);
        $apply('SPORA_PLUGIN_CATALOG_ENABLED', 'plugin_catalog_enabled', static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN));
        $apply('SPORA_PLUGIN_CATALOG_TTL', 'plugin_catalog_ttl', static fn($v) => (int) $v);

        $notifEmail = $env('SPORA_NOTIFICATIONS_EMAIL_ENABLED');
        if ($notifEmail !== null) {
            $overrides['notifications'] = ['email_enabled' => filter_var($notifEmail, FILTER_VALIDATE_BOOLEAN)];
        }

        return $overrides;
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

            // AssetStore: binary blobs produced by tools (audio, video,
            // images). Mode dispatched from `asset_store.mode` config; the
            // concrete impls below own the disk and HTTP concerns, this
            // entry just picks the strategy.
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
                $max  = (int) ($cfg['max_bytes'] ?? 50 * 1024 * 1024);
                return match ($mode) {
                    'local'    => $c->get(LocalAssetStore::class),
                    'data_url' => new DataUrlAssetStore($max),
                    'auto'     => new AutoAssetStore(
                        new DataUrlAssetStore($max),
                        $c->get(LocalAssetStore::class),
                        (int) ($cfg['auto_threshold_bytes'] ?? 1_048_576),
                    ),
                    default    => throw new InvalidArgumentException(
                        "Unknown asset_store.mode: {$mode}",
                    ),
                };
            },

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
                    $enabled ? $c->get(PluginCatalogService::class) : null,
                    $enabled,
                );
            },

            AgentController::class => static function (ContainerInterface $c): AgentController {
                return new AgentController(
                    $c->get(AuthService::class),
                    $c->get(AgentServiceInterface::class),
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
                );
            },

            TaskServiceInterface::class => static function (ContainerInterface $c): TaskServiceInterface {
                return new TaskService(
                    $c->get(OrchestratorInterface::class),
                    $c->get(MercurePublisherInterface::class),
                    $c->get(ToolCallSerializer::class),
                );
            },

            RecipeController::class => static function (ContainerInterface $c): RecipeController {
                return new RecipeController(
                    $c->get(AuthService::class),
                    $c->get(RecipeScanner::class),
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

            RecipeScanner::class => static function (ContainerInterface $c): RecipeScanner {
                $pluginLoader = $c->get(PluginLoader::class);
                $appRecipePaths = $c->has(AppLoader::class)
                    ? ($c->get(AppLoader::class)->getApp()?->recipePaths() ?? [])
                    : [];

                $directories = array_merge(
                    [$c->get(Paths::class)->recipes()],
                    $pluginLoader->recipePaths(),
                    $appRecipePaths,
                );

                return new RecipeScanner($directories);
            },

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
                );
            },

            SeedCommand::class => static function (ContainerInterface $c): SeedCommand {
                return new SeedCommand(
                    $c->get(Database::class),
                    static fn(): AuthService => $c->get(AuthService::class),
                    $c->get(EmailTemplateLoader::class),
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
        ];
    }
}
