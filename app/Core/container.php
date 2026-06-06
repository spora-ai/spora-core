<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Apps\AppRegistry;
use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Drivers\DriverFactory;
use Spora\Models\MailTemplate;
use Spora\Plugins\PluginLoader;
use Spora\Recipes\RecipeScanner;
use Spora\Services\AgentServiceInterface;
use Spora\Services\ImapClient;
use Spora\Services\ImapClientInterface;
use Spora\Services\MailTemplateService;
use Spora\Services\MailTemplateServiceInterface;
use Spora\Services\MemoryService;
use Spora\Services\MemoryServiceInterface;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\PromptTemplateService;
use Spora\Services\PromptTemplateServiceInterface;
use Spora\Services\ScheduledRunServiceInterface;
use Spora\Services\SystemMailer;
use Spora\Services\TaskService;
use Spora\Services\TaskServiceInterface;
use Spora\Services\ToolConfigService;
use Spora\Services\UserService;
use Spora\Services\UserServiceInterface;

/**
 * PHP-DI definitions array.
 * Wire up core services and resolve the SecurityManager from three possible key sources.
 */

return [
    'config' => static function (): array {
        // Layer 1 — built-in defaults (always present)
        $defaults = [
            'db_driver'           => 'sqlite',
            'db_path'             => BASE_PATH . '/storage/database.sqlite',
            'db_host'             => null,
            'db_port'             => null,
            'db_name'             => null,
            'db_user'             => null,
            'db_password'         => null,  // shared hosting: set in config.php; Docker/CI: SPORA_DB_PASSWORD
            'key_path'            => null,
            'allow_registration'  => true,
            'app_env'             => 'production',
            'log_level'           => 'WARNING',
            'log_path'            => BASE_PATH . '/storage/spora.log',
            // sync_mode (boolean, env: SPORA_SYNC_MODE=true/false)
            // worker_mode (bool): true = Sync (inline), false = Worker (queued)
            // SPORA_SYNC_MODE=true → worker_mode=true; SPORA_SYNC_MODE=false → worker_mode=false
            'worker_mode'        => true,
            'worker_stale_minutes' => 60,
            'max_workers'        => 0,  // 0 = unlimited (spawn a child for every QUEUED task)
            'llm_timeout'        => 300,
            'tool_http_timeout'   => 30,
            'mercure_url'         => null,
            'mercure_jwt_key'     => null,
            'app_url'            => Spora\Core\RequestOrigin::detect(),
        ];

        // Layer 2 — config.php (installer-generated, gitignored, optional)
        // Shared hosting users set all values here, including db_password.
        $configPath = $_ENV['SPORA_CONFIG_PATH'] ?? (getenv('SPORA_CONFIG_PATH') ?: BASE_PATH . '/config.php');
        // require (not require_once): tests reload config per-case for isolation.
        // nosonar: php:S2003 — require_once would return true on subsequent includes, breaking test isolation
        $fileConfig = file_exists($configPath) ? require $configPath : [];

        // Layer 3 — SPORA_* env vars (highest priority; Docker / VPS / CI)
        // Priority: OS env > .env file > config.php (dotenv has already run by this point)
        $env = static fn(string $k): ?string => $_ENV[$k] ?? (getenv($k) ?: null);

        $envOverrides = [];
        $applyString = static function (string $envVar, string $key) use ($env, &$envOverrides): void {
            if (($v = $env($envVar)) !== null) {
                $envOverrides[$key] = $v;
            }
        };
        $applyInt = static function (string $envVar, string $key) use ($env, &$envOverrides): void {
            if (($v = $env($envVar)) !== null) {
                $envOverrides[$key] = (int) $v;
            }
        };
        $applyBool = static function (string $envVar, string $key) use ($env, &$envOverrides): void {
            if (($v = $env($envVar)) !== null) {
                $envOverrides[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        };

        $applyString('SPORA_DB_DRIVER', 'db_driver');
        $applyString('SPORA_DB_HOST', 'db_host');
        $applyInt('SPORA_DB_PORT', 'db_port');
        $applyString('SPORA_DB_NAME', 'db_name');
        $applyString('SPORA_DB_USER', 'db_user');
        $applyString('SPORA_DB_PASSWORD', 'db_password');
        $applyInt('SPORA_SQLITE_BUSY_TIMEOUT', 'sqlite_busy_timeout');
        $applyString('SPORA_APP_ENV', 'app_env');
        $applyBool('SPORA_ALLOW_REGISTRATION', 'allow_registration');
        $applyString('SPORA_LOG_LEVEL', 'log_level');
        $applyString('SPORA_LOG_PATH', 'log_path');
        $applyBool('SPORA_SYNC_MODE', 'worker_mode');
        $applyInt('SPORA_WORKER_STALE_MINUTES', 'worker_stale_minutes');
        $applyInt('SPORA_MAX_WORKERS', 'max_workers');
        $applyInt('SPORA_LLM_TIMEOUT', 'llm_timeout');
        $applyInt('SPORA_TOOL_HTTP_TIMEOUT', 'tool_http_timeout');
        $applyString('SPORA_MERCURE_URL', 'mercure_url');
        $applyString('SPORA_MERCURE_JWT_KEY', 'mercure_jwt_key');
        $applyString('SPORA_MERCURE_PUBLISH_URL', 'mercure_publish_url');
        if (($v = $env('SPORA_NOTIFICATIONS_EMAIL_ENABLED')) !== null) {
            $envOverrides['notifications'] = ['email_enabled' => filter_var($v, FILTER_VALIDATE_BOOLEAN)];
        }
        $applyString('SPORA_APP_URL', 'app_url');
        return array_merge($defaults, $fileConfig, $envOverrides);
    },

    SecurityManagerInterface::class => static function (ContainerInterface $c): SecurityManager {
        // Resolution priority:
        // 1. SPORA_SECRET_KEY env var (base64-encoded 32-byte key)
        // 2. SPORA_KEY_PATH env var (path to key file)
        // 3. config['key_path'] (set by install.php)

        $envKey     = $_ENV['SPORA_SECRET_KEY'] ?? getenv('SPORA_SECRET_KEY') ?: null;
        $envKeyPath = $_ENV['SPORA_KEY_PATH']    ?? getenv('SPORA_KEY_PATH') ?: null;

        if ($envKey !== null) {
            $rawKey = base64_decode($envKey, strict: true);
            if ($rawKey === false) {
                throw new RuntimeException(
                    'SPORA_SECRET_KEY is not valid base64. Regenerate with: base64_encode(random_bytes(32))',
                );
            }
            return new SecurityManager($rawKey);
        }

        if ($envKeyPath !== null) {
            return new SecurityManager($envKeyPath);
        }

        $config  = $c->get('config');
        $keyPath = $config['key_path'] ?? null;

        if ($keyPath !== null) {
            return new SecurityManager((string) $keyPath);
        }

        throw new RuntimeException(
            'No secret key configured. Set SPORA_SECRET_KEY (base64 32 bytes), ' .
            'SPORA_KEY_PATH, or run install.php to generate storage/secret.key.',
        );
    },

    Database::class => static function (ContainerInterface $c): Database {
        $db = new Database($c->get('config'), $c->get(PluginLoader::class));
        $db->bootDatabaseConnectionOnly();
        return $db;
    },

    LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
        $config = $c->get('config');
        $levelStr = ucfirst(strtolower($config['log_level'] ?? 'warning'));
        $level = constant(Monolog\Level::class . '::' . $levelStr);

        $logger = new Monolog\Logger('spora');

        // Docker: SPORA_LOG_PATH=stdout writes to stdout (supervisord → Docker log driver).
        $logPath = $config['log_path'] ?? (BASE_PATH . '/storage/spora.log');
        $stream = ($logPath === 'stdout') ? 'php://stdout' : $logPath;
        $handler = new Monolog\Handler\StreamHandler($stream, $level);

        $logger->pushHandler($handler);

        return $logger;
    },

    Delight\Auth\Auth::class => static function (ContainerInterface $c): Delight\Auth\Auth {
        $pdo = Illuminate\Database\Capsule\Manager::connection()->getPdo();

        // Disable throttling in test/development environments to prevent rate-limit errors.
        $config      = $c->get('config');
        $throttling  = ($config['app_env'] ?? 'production') !== 'testing';

        return new Delight\Auth\Auth($pdo, null, null, $throttling);
    },

    AuthService::class => static function (ContainerInterface $c): AuthService {
        $authService = new AuthService($c->get(Delight\Auth\Auth::class));
        $authService->setSystemMailer($c->get(SystemMailer::class));
        $authService->setAppUrl($c->get('config')['app_url'] ?? 'http://localhost');
        return $authService;
    },

    Spora\Security\CsrfTokenService::class => static function (ContainerInterface $c): Spora\Security\CsrfTokenService {
        return new Spora\Security\CsrfTokenService(
            $c->get(LoggerInterface::class),
        );
    },

    Spora\Http\Middleware\AuthMiddleware::class => static function (ContainerInterface $c): Spora\Http\Middleware\AuthMiddleware {
        return new Spora\Http\Middleware\AuthMiddleware(
            $c->get(AuthService::class),
        );
    },

    Spora\Http\Middleware\CsrfMiddleware::class => static function (ContainerInterface $c): Spora\Http\Middleware\CsrfMiddleware {
        return new Spora\Http\Middleware\CsrfMiddleware(
            $c->get(Spora\Security\CsrfTokenService::class),
            $c->get(LoggerInterface::class),
        );
    },

    Symfony\Contracts\HttpClient\HttpClientInterface::class => static function (): Symfony\Contracts\HttpClient\HttpClientInterface {
        return Symfony\Component\HttpClient\HttpClient::create();
    },

    Spora\Http\AuthController::class => static function (ContainerInterface $c): Spora\Http\AuthController {
        return new Spora\Http\AuthController(
            $c->get(AuthService::class),
            $c->get(Spora\Security\CsrfTokenService::class),
            $c->get(Spora\Services\AuthValidator::class),
            $c->get(Spora\Services\AuthWorkflow::class),
            $c->get('config'),
        );
    },

    Spora\Services\AuthWorkflow::class => static function (ContainerInterface $c): Spora\Services\AuthWorkflow {
        return new Spora\Services\AuthWorkflow(
            $c->get(AuthService::class),
            $c->get(UserServiceInterface::class),
            $c->get(Spora\Security\CsrfTokenService::class),
            $c->get(Spora\Services\AuthValidator::class),
        );
    },

    Spora\Services\AuthValidator::class => static fn(): Spora\Services\AuthValidator => new Spora\Services\AuthValidator(),

    ToolConfigService::class => static function (ContainerInterface $c): ToolConfigService {
        return new ToolConfigService(
            $c->get(SecurityManagerInterface::class),
            $c->get(LoggerInterface::class),
            $c->get('tool_classes'),
        );
    },

    ImapClientInterface::class => static function (ContainerInterface $c): ImapClientInterface {
        return new ImapClient(
            $c->get(LoggerInterface::class),
        );
    },

    DriverFactory::class => static function (ContainerInterface $c): DriverFactory {
        return new DriverFactory(
            $c->get(LoggerInterface::class),
            $c->get(Spora\Services\LLMConfigServiceInterface::class),
            (int) ($c->get('config')['llm_timeout'] ?? 300),
        );
    },

    // Registered LLM driver classes (implementing LLMDriverConfigInterface).
    // Each driver declares its settings schema via #[ToolSetting] attributes.
    'llm_driver_classes' => [
        Spora\Drivers\OpenAICompatibleDriver::class,
        Spora\Drivers\AnthropicCompatibleDriver::class,
    ],

    // Registered apps. Add to this list to make apps discoverable via GET /api/v1/apps.
    'app_apps' => [
        Spora\Apps\MemoriesApp::class,
    ],

    AppRegistry::class => static function (ContainerInterface $c): AppRegistry {
        $registry = new AppRegistry();
        foreach ($c->get('app_apps') as $appClass) {
            $registry->register($appClass);
        }
        return $registry;
    },

    // Registered tool classes. Add to this list to make tools discoverable via GET /api/v1/tools.
    // Settings (#[ToolSetting]) live directly on each tool class. See docs/06_tools.md.
    'tool_classes' => [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\AgentMemoryTool::class,
        Spora\Tools\GlobalMemoryTool::class,
        Spora\Tools\TavilySearchTool::class,
        Spora\Tools\SerperSearchTool::class,
        Spora\Tools\ReadUrlTool::class,
        Spora\Tools\WorldNewsApiTool::class,
        Spora\Tools\EmailTool::class,
        Spora\Tools\CalDavCalendarTool::class,
        Spora\Tools\UserInfoTool::class,
        Spora\Tools\SemanticScholarTool::class,
        Spora\Tools\WeatherApiTool::class,
    ],

    Spora\Http\LLMConfigController::class => static function (ContainerInterface $c): Spora\Http\LLMConfigController {
        return new Spora\Http\LLMConfigController(
            $c->get(AuthService::class),
            $c->get(Spora\Services\LLMConfigServiceInterface::class),
            $c->get(Spora\Services\LlmConfigValidator::class),
        );
    },

    Spora\Services\LlmConfigValidator::class => static function (ContainerInterface $c): Spora\Services\LlmConfigValidator {
        return new Spora\Services\LlmConfigValidator(
            $c->get(Spora\Services\LLMConfigServiceInterface::class),
        );
    },

    // Tool definitions - explicit construction ensures the logger is injected (PHP-DI autowiring misses optional LoggerInterface).
    Spora\Tools\CurrentTimeTool::class => static function (): Spora\Tools\CurrentTimeTool {
        return new Spora\Tools\CurrentTimeTool();
    },
    Spora\Tools\CalculatorTool::class => static function (): Spora\Tools\CalculatorTool {
        return new Spora\Tools\CalculatorTool();
    },
    Spora\Tools\AgentMemoryTool::class => static function (): Spora\Tools\AgentMemoryTool {
        return new Spora\Tools\AgentMemoryTool();
    },
    Spora\Tools\GlobalMemoryTool::class => static function (): Spora\Tools\GlobalMemoryTool {
        return new Spora\Tools\GlobalMemoryTool();
    },
    Spora\Tools\TavilySearchTool::class => static function (ContainerInterface $c): Spora\Tools\TavilySearchTool {
        return new Spora\Tools\TavilySearchTool(
            $c->get(ToolConfigService::class),
            $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $c->get(LoggerInterface::class),
        );
    },
    Spora\Tools\SerperSearchTool::class => static function (ContainerInterface $c): Spora\Tools\SerperSearchTool {
        return new Spora\Tools\SerperSearchTool(
            $c->get(ToolConfigService::class),
            $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $c->get(LoggerInterface::class),
        );
    },
    Spora\Tools\ReadUrlTool::class => static function (ContainerInterface $c): Spora\Tools\ReadUrlTool {
        return new Spora\Tools\ReadUrlTool(
            $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $c->get(ToolConfigService::class),
            $c->get(LoggerInterface::class),
        );
    },
    Spora\Tools\WorldNewsApiTool::class => static function (ContainerInterface $c): Spora\Tools\WorldNewsApiTool {
        return new Spora\Tools\WorldNewsApiTool(
            $c->get(ToolConfigService::class),
            $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $c->get(LoggerInterface::class),
        );
    },
    Spora\Tools\EmailTool::class => static function (ContainerInterface $c): Spora\Tools\EmailTool {
        return new Spora\Tools\EmailTool(
            $c->get(ToolConfigService::class),
            $c->get(ImapClientInterface::class),
            $c->get(LoggerInterface::class),
        );
    },
    Spora\Tools\CalDavCalendarTool::class => static function (ContainerInterface $c): Spora\Tools\CalDavCalendarTool {
        return new Spora\Tools\CalDavCalendarTool(
            $c->get(ToolConfigService::class),
            $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $c->get(LoggerInterface::class),
            $c->get('config'),
        );
    },
    Spora\Tools\UserInfoTool::class => static function (): Spora\Tools\UserInfoTool {
        return new Spora\Tools\UserInfoTool();
    },
    Spora\Tools\SemanticScholarTool::class => static function (ContainerInterface $c): Spora\Tools\SemanticScholarTool {
        return new Spora\Tools\SemanticScholarTool(
            $c->get(ToolConfigService::class),
            $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $c->get(LoggerInterface::class),
        );
    },
    Spora\Tools\WeatherApiTool::class => static function (ContainerInterface $c): Spora\Tools\WeatherApiTool {
        return new Spora\Tools\WeatherApiTool(
            $c->get(ToolConfigService::class),
            $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $c->get(LoggerInterface::class),
        );
    },

    Spora\Http\UserPreferenceController::class => static function (ContainerInterface $c): Spora\Http\UserPreferenceController {
        return new Spora\Http\UserPreferenceController(
            $c->get(AuthService::class),
            $c->get(Spora\Services\LLMConfigServiceInterface::class),
        );
    },

    Spora\Services\LLMConfigService::class => static function (ContainerInterface $c): Spora\Services\LLMConfigService {
        return new Spora\Services\LLMConfigService(
            $c->get(SecurityManagerInterface::class),
            $c->get('llm_driver_classes'),
        );
    },

    Spora\Services\LLMConfigServiceInterface::class => static function (ContainerInterface $c): Spora\Services\LLMConfigServiceInterface {
        return $c->get(Spora\Services\LLMConfigService::class);
    },

    AgentServiceInterface::class => static function (ContainerInterface $c): AgentServiceInterface {
        return new Spora\Services\AgentService(
            $c->get(ToolConfigService::class),
            $c->get(Spora\Services\LLMConfigService::class),
        );
    },

    UserServiceInterface::class => static function (): UserServiceInterface {
        return new UserService();
    },

    Spora\Http\AppsController::class => static function (ContainerInterface $c): Spora\Http\AppsController {
        return new Spora\Http\AppsController(
            $c->get(AppRegistry::class),
        );
    },

    Spora\Http\MemoryController::class => static function (ContainerInterface $c): Spora\Http\MemoryController {
        return new Spora\Http\MemoryController(
            $c->get(AuthService::class),
            $c->get(MemoryServiceInterface::class),
        );
    },

    Spora\Http\AgentMemoryController::class => static function (ContainerInterface $c): Spora\Http\AgentMemoryController {
        return new Spora\Http\AgentMemoryController(
            $c->get(AuthService::class),
            $c->get(MemoryServiceInterface::class),
        );
    },

    Spora\Http\AgentController::class => static function (ContainerInterface $c): Spora\Http\AgentController {
        return new Spora\Http\AgentController(
            $c->get(AuthService::class),
            $c->get(AgentServiceInterface::class),
            $c->get(ToolConfigService::class),
        );
    },

    Spora\Http\HealthController::class => static fn(): Spora\Http\HealthController => new Spora\Http\HealthController(),

    Spora\Http\ConfigController::class => static function (ContainerInterface $c): Spora\Http\ConfigController {
        return new Spora\Http\ConfigController(
            $c->get('config'),
        );
    },

    Spora\Http\ToolController::class => static function (ContainerInterface $c): Spora\Http\ToolController {
        return new Spora\Http\ToolController(
            $c->get(AuthService::class),
            $c->get(ToolConfigService::class),
            $c->get('tool_classes'),
        );
    },

    // Registered tool instances for the Orchestrator.
    // Instantiated from tool_classes via PHP-DI autowiring.
    'tool_instances' => static function (ContainerInterface $c): array {
        return array_map(
            fn(string $toolClass) => $c->get($toolClass),
            $c->get('tool_classes'),
        );
    },

    Spora\Services\ToolCallSerializer::class => static function (ContainerInterface $c): Spora\Services\ToolCallSerializer {
        return new Spora\Services\ToolCallSerializer(
            toolInstances: $c->get('tool_instances'),
        );
    },

    Spora\Console\Commands\SetupCommand::class => static function (ContainerInterface $c): Spora\Console\Commands\SetupCommand {
        return new Spora\Console\Commands\SetupCommand(
            $c->get(Database::class),
            $c->get(DatabaseSchemaInstaller::class),
            $c->get(AuthService::class),
            $c->get(Spora\Services\EmailTemplateLoader::class),
        );
    },

    Spora\Console\Commands\SeedCommand::class => static function (ContainerInterface $c): Spora\Console\Commands\SeedCommand {
        return new Spora\Console\Commands\SeedCommand(
            $c->get(Database::class),
            // Closure defers AuthService (and Delight\Auth\Auth → PDO) construction until after
            // bootDatabaseConnectionOnly() has been called inside execute().
            static fn(): AuthService => $c->get(AuthService::class),
            $c->get(Spora\Services\EmailTemplateLoader::class),
        );
    },

    Spora\Console\Commands\WorkerRunCommand::class => static function (ContainerInterface $c): Spora\Console\Commands\WorkerRunCommand {
        return new Spora\Console\Commands\WorkerRunCommand(
            $c->get(Database::class),
            $c->get(OrchestratorInterface::class),
            $c->get(LoggerInterface::class),
            $c,
            $c->get(MercurePublisherInterface::class),
            $c->get(NotificationService::class),
        );
    },

    Spora\Console\Commands\TaskRunCommand::class => static function (ContainerInterface $c): Spora\Console\Commands\TaskRunCommand {
        return new Spora\Console\Commands\TaskRunCommand(
            $c->get(Database::class),
            $c,
            $c->get(MercurePublisherInterface::class),
        );
    },

    OrchestratorInterface::class => static function (ContainerInterface $c): OrchestratorInterface {
        return new Orchestrator(
            driverFactory: $c->get(DriverFactory::class),
            llmConfigService: $c->get(Spora\Services\LLMConfigService::class),
            toolInstances: $c->get('tool_instances'),
            logger: $c->get(LoggerInterface::class),
            workerMode: ($c->get('config')['worker_mode'] ?? true) ? WorkerMode::Sync : WorkerMode::Worker,
            notificationService: $c->get(NotificationService::class),
            pluginLoader: $c->get(PluginLoader::class),
            mercure: $c->get(MercurePublisherInterface::class),
            toolConfigService: $c->get(ToolConfigService::class),
            toolCallSerializer: $c->get(Spora\Services\ToolCallSerializer::class),
        );
    },

    MercurePublisherInterface::class => static function (ContainerInterface $c): MercurePublisherInterface {
        $config   = $c->get('config');
        $hubUrl   = $config['mercure_publish_url'] ?? $config['mercure_url'] ?? null;
        $jwtKey   = $config['mercure_jwt_key'] ?? null;
        $client   = $c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class);

        return new Spora\Services\MercurePublisher($client, $hubUrl, $jwtKey, $c->get(LoggerInterface::class));
    },

    Spora\Http\TaskController::class => static function (ContainerInterface $c): Spora\Http\TaskController {
        return new Spora\Http\TaskController(
            $c->get(AuthService::class),
            $c->get(TaskServiceInterface::class),
        );
    },

    TaskServiceInterface::class => static function (ContainerInterface $c): TaskServiceInterface {
        return new TaskService(
            $c->get(OrchestratorInterface::class),
            $c->get(MercurePublisherInterface::class),
            $c->get(Spora\Services\ToolCallSerializer::class),
        );
    },

    PluginLoader::class => static function (): PluginLoader {
        $loader = new PluginLoader(BASE_PATH . '/plugins');
        $loader->boot();
        return $loader;
    },

    RecipeScanner::class => static function (ContainerInterface $c): RecipeScanner {
        $pluginLoader = $c->get(PluginLoader::class);

        $directories = array_merge(
            [BASE_PATH . '/recipes'],
            $pluginLoader->recipePaths(),
        );

        return new RecipeScanner($directories);
    },

    Spora\Http\RecipeController::class => static function (ContainerInterface $c): Spora\Http\RecipeController {
        return new Spora\Http\RecipeController(
            $c->get(AuthService::class),
            $c->get(RecipeScanner::class),
        );
    },

    NotificationService::class => static function (ContainerInterface $c): NotificationService {
        return new NotificationService(
            $c->get(MercurePublisherInterface::class),
            $c->get(SystemMailer::class),
            $c->get('config'),
        );
    },

    Spora\Services\NotificationServiceInterface::class => static function (ContainerInterface $c): Spora\Services\NotificationServiceInterface {
        return $c->get(NotificationService::class);
    },

    Spora\Http\NotificationController::class => static function (ContainerInterface $c): Spora\Http\NotificationController {
        return new Spora\Http\NotificationController(
            $c->get(AuthService::class),
            $c->get(Spora\Services\NotificationServiceInterface::class),
        );
    },

    PromptTemplateServiceInterface::class => static function (): PromptTemplateServiceInterface {
        return new PromptTemplateService();
    },

    MemoryServiceInterface::class => static function (): MemoryServiceInterface {
        return new MemoryService();
    },

    MailTemplateServiceInterface::class => static function (): MailTemplateServiceInterface {
        return new MailTemplateService();
    },

    Spora\Services\EmailTemplateLoader::class => static function (): Spora\Services\EmailTemplateLoader {
        return new Spora\Services\EmailTemplateLoader();
    },

    Spora\Http\PromptTemplateController::class => static function (ContainerInterface $c): Spora\Http\PromptTemplateController {
        return new Spora\Http\PromptTemplateController(
            $c->get(AuthService::class),
            $c->get(PromptTemplateServiceInterface::class),
        );
    },

    ScheduledRunServiceInterface::class => static function (ContainerInterface $c): ScheduledRunServiceInterface {
        return new Spora\Services\ScheduledRunService(
            $c->get(OrchestratorInterface::class),
            $c->get(MercurePublisherInterface::class),
        );
    },

    Spora\Http\ScheduledRunController::class => static function (ContainerInterface $c): Spora\Http\ScheduledRunController {
        return new Spora\Http\ScheduledRunController(
            $c->get(AuthService::class),
            $c->get(ScheduledRunServiceInterface::class),
        );
    },

    Spora\Http\SseController::class => static function (ContainerInterface $c): Spora\Http\SseController {
        $config = $c->get('config');
        return new Spora\Http\SseController(
            $c->get(AuthService::class),
            $config['mercure_publish_url'] ?? null,
            $config['mercure_jwt_key'] ?? null,
            '/.well-known/mercure',
        );
    },

    Spora\Http\UserProfileController::class => static function (ContainerInterface $c): Spora\Http\UserProfileController {
        return new Spora\Http\UserProfileController(
            $c->get(AuthService::class),
            $c->get(UserServiceInterface::class),
        );
    },

    Spora\Http\Middleware\AdminMiddleware::class => static function (ContainerInterface $c): Spora\Http\Middleware\AdminMiddleware {
        return new Spora\Http\Middleware\AdminMiddleware(
            $c->get(AuthService::class),
        );
    },

    Spora\Http\UserController::class => static function (ContainerInterface $c): Spora\Http\UserController {
        return new Spora\Http\UserController(
            $c->get(AuthService::class),
            $c->get(UserServiceInterface::class),
        );
    },

    Spora\Http\MailConfigController::class => static function (ContainerInterface $c): Spora\Http\MailConfigController {
        return new Spora\Http\MailConfigController(
            $c->get(AuthService::class),
            $c->get(SystemMailer::class),
            $c->get('config'),
        );
    },

    Spora\Http\MailTemplateController::class => static function (ContainerInterface $c): Spora\Http\MailTemplateController {
        return new Spora\Http\MailTemplateController(
            $c->get(MailTemplateServiceInterface::class),
        );
    },

    SystemMailer::class => static function (ContainerInterface $c): SystemMailer {
        return new SystemMailer(
            $c->get('config'),
            $c->get(LoggerInterface::class),
        );
    },

    MailTemplate::class => static fn(): MailTemplate => new MailTemplate(),
];
