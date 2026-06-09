<?php

declare(strict_types=1);

namespace Spora\Core;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Delight\Auth\Auth as DelightAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Apps\AppRegistry;
use Spora\Auth\AuthService;
use Spora\Core\Exceptions\InvalidSecretKeyException;
use Spora\Core\Exceptions\MissingSecretKeyException;
use Spora\Http\Controllers as HttpControllers;
use Spora\Apps\MemoriesApp;
use Spora\Console\Commands\SeedCommand;
use Spora\Console\Commands\SetupCommand;
use Spora\Console\Commands\TaskRunCommand;
use Spora\Console\Commands\WorkerRunCommand;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
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
use Spora\Security\CsrfTokenService;
use Spora\Services\AgentService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\LLMConfigService;
use Spora\Services\LLMConfigServiceInterface;
use Spora\Services\LlmConfigValidator;
use Spora\Services\MercurePublisher;
use Spora\Services\NotificationServiceInterface;
use Spora\Services\ScheduledRunService;
use Spora\Services\ToolCallSerializer;
use Spora\Plugins\PluginLoader;
use Spora\Recipes\RecipeScanner;
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
use Spora\Tools\AgentMemoryTool;
use Spora\Tools\CalculatorTool;
use Spora\Tools\CalDavCalendarTool;
use Spora\Tools\CurrentTimeTool;
use Spora\Tools\EmailTool;
use Spora\Tools\GlobalMemoryTool;
use Spora\Tools\ReadUrlTool;
use Spora\Tools\SemanticScholarTool;
use Spora\Tools\SerperSearchTool;
use Spora\Tools\TavilySearchTool;
use Spora\Tools\UserInfoTool;
use Spora\Tools\WeatherApiTool;
use Spora\Tools\WorldNewsApiTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContainerDefinitions
{
    public static function all(): array
    {
        return [
            'config' => static function (): array {
                $defaults = [
                    'db_driver'           => 'sqlite',
                    'db_path'             => BASE_PATH . '/storage/database.sqlite',
                    'db_host'             => null,
                    'db_port'             => null,
                    'db_name'             => null,
                    'db_user'             => null,
                    'db_password'         => null,
                    'key_path'            => null,
                    'allow_registration'  => true,
                    'app_env'             => 'production',
                    'log_level'           => 'WARNING',
                    'log_path'            => BASE_PATH . '/storage/spora.log',
                    'worker_mode'         => true,
                    'worker_stale_minutes' => 60,
                    'max_workers'         => 0,
                    'llm_timeout'         => 300,
                    'tool_http_timeout'   => 30,
                    'mercure_url'         => null,
                    'mercure_jwt_key'     => null,
                    'app_url'             => \Spora\Core\RequestOrigin::detect(),
                ];

                $configPath = $_ENV['SPORA_CONFIG_PATH'] ?? (getenv('SPORA_CONFIG_PATH') ?: BASE_PATH . '/config.php');
                $fileConfig = \Spora\Core\UserConfig::load($configPath);

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
                $envKey     = $_ENV['SPORA_SECRET_KEY'] ?? getenv('SPORA_SECRET_KEY') ?: null;
                $envKeyPath = $_ENV['SPORA_KEY_PATH']    ?? getenv('SPORA_KEY_PATH') ?: null;

                if ($envKey !== null) {
                    $rawKey = base64_decode($envKey, strict: true);
                    if ($rawKey === false) {
                        throw new InvalidSecretKeyException(
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

                throw new MissingSecretKeyException(
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
                $level = constant(Level::class . '::' . $levelStr);

                $logger = new MonologLogger('spora');

                $logPath = $config['log_path'] ?? (BASE_PATH . '/storage/spora.log');
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
                    $c->get(LLMConfigServiceInterface::class),
                    (int) ($c->get('config')['llm_timeout'] ?? 300),
                );
            },

            'llm_driver_classes' => [
                OpenAICompatibleDriver::class,
                AnthropicCompatibleDriver::class,
            ],

            'app_apps' => [
                MemoriesApp::class,
            ],

            AppRegistry::class => static function (ContainerInterface $c): AppRegistry {
                $registry = new AppRegistry();
                foreach ($c->get('app_apps') as $appClass) {
                    $registry->register($appClass);
                }
                return $registry;
            },

            'tool_classes' => [
                CurrentTimeTool::class,
                CalculatorTool::class,
                AgentMemoryTool::class,
                GlobalMemoryTool::class,
                TavilySearchTool::class,
                SerperSearchTool::class,
                ReadUrlTool::class,
                WorldNewsApiTool::class,
                EmailTool::class,
                CalDavCalendarTool::class,
                UserInfoTool::class,
                SemanticScholarTool::class,
                WeatherApiTool::class,
            ],

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

            CurrentTimeTool::class => static fn(): CurrentTimeTool => new CurrentTimeTool(),
            CalculatorTool::class => static fn(): CalculatorTool => new CalculatorTool(),
            AgentMemoryTool::class => static fn(): AgentMemoryTool => new AgentMemoryTool(),
            GlobalMemoryTool::class => static fn(): GlobalMemoryTool => new GlobalMemoryTool(),

            TavilySearchTool::class => static function (ContainerInterface $c): TavilySearchTool {
                return new TavilySearchTool(
                    $c->get(ToolConfigService::class),
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                );
            },

            SerperSearchTool::class => static function (ContainerInterface $c): SerperSearchTool {
                return new SerperSearchTool(
                    $c->get(ToolConfigService::class),
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                );
            },

            ReadUrlTool::class => static function (ContainerInterface $c): ReadUrlTool {
                return new ReadUrlTool(
                    $c->get(HttpClientInterface::class),
                    $c->get(ToolConfigService::class),
                    $c->get(LoggerInterface::class),
                );
            },

            WorldNewsApiTool::class => static function (ContainerInterface $c): WorldNewsApiTool {
                return new WorldNewsApiTool(
                    $c->get(ToolConfigService::class),
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                );
            },

            EmailTool::class => static function (ContainerInterface $c): EmailTool {
                return new EmailTool(
                    $c->get(ToolConfigService::class),
                    $c->get(ImapClientInterface::class),
                    $c->get(LoggerInterface::class),
                );
            },

            CalDavCalendarTool::class => static function (ContainerInterface $c): CalDavCalendarTool {
                return new CalDavCalendarTool(
                    $c->get(ToolConfigService::class),
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                    $c->get('config'),
                );
            },

            UserInfoTool::class => static fn(): UserInfoTool => new UserInfoTool(),

            SemanticScholarTool::class => static function (ContainerInterface $c): SemanticScholarTool {
                return new SemanticScholarTool(
                    $c->get(ToolConfigService::class),
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                );
            },

            WeatherApiTool::class => static function (ContainerInterface $c): WeatherApiTool {
                return new WeatherApiTool(
                    $c->get(ToolConfigService::class),
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                );
            },

            UserPreferenceController::class => static function (ContainerInterface $c): UserPreferenceController {
                return new UserPreferenceController(
                    $c->get(AuthService::class),
                    $c->get(LLMConfigServiceInterface::class),
                );
            },

            LLMConfigService::class => static function (ContainerInterface $c): LLMConfigService {
                return new LLMConfigService(
                    $c->get(SecurityManagerInterface::class),
                    $c->get('llm_driver_classes'),
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

            ConfigController::class => static function (ContainerInterface $c): ConfigController {
                return new ConfigController(
                    $c->get('config'),
                );
            },

            ToolController::class => static function (ContainerInterface $c): ToolController {
                return new ToolController(
                    $c->get(AuthService::class),
                    $c->get(ToolConfigService::class),
                    $c->get('tool_classes'),
                );
            },

            'tool_instances' => static function (ContainerInterface $c): array {
                return array_map(
                    fn(string $toolClass) => $c->get($toolClass),
                    $c->get('tool_classes'),
                );
            },

            ToolCallSerializer::class => static function (ContainerInterface $c): ToolCallSerializer {
                return new ToolCallSerializer(
                    toolInstances: $c->get('tool_instances'),
                );
            },

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
                );
            },

            TaskRunCommand::class => static function (ContainerInterface $c): TaskRunCommand {
                return new TaskRunCommand(
                    $c->get(Database::class),
                    $c,
                    $c->get(MercurePublisherInterface::class),
                );
            },

            OrchestratorInterface::class => static function (ContainerInterface $c): OrchestratorInterface {
                return new Orchestrator(
                    driverFactory: $c->get(DriverFactory::class),
                    llmConfigService: $c->get(LLMConfigService::class),
                    toolInstances: $c->get('tool_instances'),
                    logger: $c->get(LoggerInterface::class),
                    workerMode: ($c->get('config')['worker_mode'] ?? true) ? WorkerMode::Sync : WorkerMode::Worker,
                    notificationService: $c->get(NotificationService::class),
                    pluginLoader: $c->get(PluginLoader::class),
                    mercure: $c->get(MercurePublisherInterface::class),
                    toolConfigService: $c->get(ToolConfigService::class),
                    toolCallSerializer: $c->get(ToolCallSerializer::class),
                );
            },

            MercurePublisherInterface::class => static function (ContainerInterface $c): MercurePublisherInterface {
                $config   = $c->get('config');
                $hubUrl   = $config['mercure_publish_url'] ?? $config['mercure_url'] ?? null;
                $jwtKey   = $config['mercure_jwt_key'] ?? null;
                $client   = $c->get(HttpClientInterface::class);

                return new MercurePublisher($client, $hubUrl, $jwtKey, $c->get(LoggerInterface::class));
            },

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

            RecipeController::class => static function (ContainerInterface $c): RecipeController {
                return new RecipeController(
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

            NotificationServiceInterface::class => static function (ContainerInterface $c): NotificationServiceInterface {
                return $c->get(NotificationService::class);
            },

            NotificationController::class => static function (ContainerInterface $c): NotificationController {
                return new NotificationController(
                    $c->get(AuthService::class),
                    $c->get(NotificationServiceInterface::class),
                );
            },

            PromptTemplateServiceInterface::class => static fn(): PromptTemplateServiceInterface => new PromptTemplateService(),

            MemoryServiceInterface::class => static fn(): MemoryServiceInterface => new MemoryService(),

            MailTemplateServiceInterface::class => static fn(): MailTemplateServiceInterface => new MailTemplateService(),

            EmailTemplateLoader::class => static fn(): EmailTemplateLoader => new EmailTemplateLoader(),

            PromptTemplateController::class => static function (ContainerInterface $c): PromptTemplateController {
                return new PromptTemplateController(
                    $c->get(AuthService::class),
                    $c->get(PromptTemplateServiceInterface::class),
                );
            },

            ScheduledRunServiceInterface::class => static function (ContainerInterface $c): ScheduledRunServiceInterface {
                return new ScheduledRunService(
                    $c->get(OrchestratorInterface::class),
                    $c->get(MercurePublisherInterface::class),
                );
            },

            ScheduledRunController::class => static function (ContainerInterface $c): ScheduledRunController {
                return new ScheduledRunController(
                    $c->get(AuthService::class),
                    $c->get(ScheduledRunServiceInterface::class),
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

            UserProfileController::class => static function (ContainerInterface $c): UserProfileController {
                return new UserProfileController(
                    $c->get(AuthService::class),
                    $c->get(UserServiceInterface::class),
                );
            },

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

            SystemMailer::class => static function (ContainerInterface $c): SystemMailer {
                return new SystemMailer(
                    $c->get('config'),
                    $c->get(LoggerInterface::class),
                );
            },

            MailTemplate::class => static fn(): MailTemplate => new MailTemplate(),
        ];
    }
}
