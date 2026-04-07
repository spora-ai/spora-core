<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Spora\Agents\Messages\TickMessage;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\OrchestratorProxy;
use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Plugins\PluginLoader;
use Spora\Recipes\RecipeScanner;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

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
        ];

        // Layer 2 — config.php (installer-generated, gitignored, optional)
        // Shared hosting users set all values here, including db_password.
        $configPath = $_ENV['SPORA_CONFIG_PATH'] ?? (getenv('SPORA_CONFIG_PATH') ?: BASE_PATH . '/config.php');
        $fileConfig = file_exists($configPath) ? require $configPath : [];

        // Layer 3 — SPORA_* env vars (highest priority; Docker / VPS / CI)
        // Priority: OS env > .env file > config.php (dotenv has already run by this point)
        $env = static fn(string $k): ?string => $_ENV[$k] ?? (getenv($k) ?: null);

        $envOverrides = [];
        if (($v = $env('SPORA_DB_DRIVER'))           !== null) {
            $envOverrides['db_driver']          = $v;
        }
        if (($v = $env('SPORA_DB_HOST'))             !== null) {
            $envOverrides['db_host']             = $v;
        }
        if (($v = $env('SPORA_DB_PORT'))             !== null) {
            $envOverrides['db_port']             = (int) $v;
        }
        if (($v = $env('SPORA_DB_NAME'))             !== null) {
            $envOverrides['db_name']             = $v;
        }
        if (($v = $env('SPORA_DB_USER'))             !== null) {
            $envOverrides['db_user']             = $v;
        }
        if (($v = $env('SPORA_DB_PASSWORD'))         !== null) {
            $envOverrides['db_password']         = $v;
        }
        if (($v = $env('SPORA_APP_ENV'))             !== null) {
            $envOverrides['app_env']             = $v;
        }
        if (($v = $env('SPORA_ALLOW_REGISTRATION'))  !== null) {
            $envOverrides['allow_registration']  = filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }
        if (($v = $env('SPORA_LOG_LEVEL')) !== null) {
            $envOverrides['log_level'] = $v;
        }
        if (($v = $env('SPORA_LOG_PATH')) !== null) {
            $envOverrides['log_path'] = $v;
        }
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
        return new Database($c->get('config'), $c->get(PluginLoader::class));
    },

    Psr\Log\LoggerInterface::class => static function (ContainerInterface $c): Psr\Log\LoggerInterface {
        $config = $c->get('config');
        $levelStr = ucfirst(strtolower($config['log_level'] ?? 'warning'));
        $level = constant(Monolog\Level::class . '::' . $levelStr);

        $logger = new Monolog\Logger('spora');
        $handler = new Monolog\Handler\StreamHandler($config['log_path'] ?? (BASE_PATH . '/storage/spora.log'), $level);
        // Explicitly set permissions for shared hosting (e.g., 0664) - though StreamHandler respects umask natively.
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
        return new AuthService($c->get(Delight\Auth\Auth::class));
    },

    Symfony\Contracts\HttpClient\HttpClientInterface::class => static function (): Symfony\Contracts\HttpClient\HttpClientInterface {
        return Symfony\Component\HttpClient\HttpClient::create();
    },

    Spora\Http\AuthController::class => static function (ContainerInterface $c): Spora\Http\AuthController {
        return new Spora\Http\AuthController(
            $c->get(AuthService::class),
            $c->get('config'),
        );
    },

    Spora\Services\ToolConfigService::class => static function (ContainerInterface $c): Spora\Services\ToolConfigService {
        return new Spora\Services\ToolConfigService(
            $c->get(SecurityManagerInterface::class),
            $c->get('tool_classes'),
        );
    },

    Spora\Drivers\DriverFactory::class => static function (ContainerInterface $c): Spora\Drivers\DriverFactory {
        return new Spora\Drivers\DriverFactory(
            $c->get(Psr\Log\LoggerInterface::class),
            $c->get(Spora\Services\LLMConfigService::class),
        );
    },

    // Registered LLM driver classes (implementing LLMDriverConfigInterface).
    // Each driver declares its settings schema via #[ToolSetting] attributes.
    'llm_driver_classes' => [
        Spora\Drivers\OpenAICompatibleDriver::class,
        Spora\Drivers\AnthropicCompatibleDriver::class,
    ],

    // Registered tool classes. Add to this list to make tools discoverable via GET /api/v1/tools.
    // Settings (#[ToolSetting]) live directly on each tool class. See docs/06_tools.md.
    'tool_classes' => [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\ScratchpadTool::class,
        Spora\Tools\TavilySearchTool::class,
        Spora\Tools\SerperSearchTool::class,
        Spora\Tools\ReadUrlTool::class,
        Spora\Tools\NewsApiTool::class,
        Spora\Tools\GNewsTool::class,
        Spora\Tools\ReadEmailTool::class,
        Spora\Tools\SendEmailTool::class,
        Spora\Tools\CalDavCalendarTool::class,
    ],

    Spora\Http\LLMConfigController::class => static function (ContainerInterface $c): Spora\Http\LLMConfigController {
        return new Spora\Http\LLMConfigController(
            $c->get(AuthService::class),
            $c->get(Spora\Services\LLMConfigService::class),
        );
    },

    Spora\Services\LLMConfigService::class => static function (ContainerInterface $c): Spora\Services\LLMConfigService {
        return new Spora\Services\LLMConfigService(
            $c->get(SecurityManagerInterface::class),
            $c->get('llm_driver_classes'),
        );
    },

    Spora\Http\AgentController::class => static function (ContainerInterface $c): Spora\Http\AgentController {
        return new Spora\Http\AgentController(
            $c->get(AuthService::class),
            $c->get(Spora\Services\ToolConfigService::class),
            $c->get(Spora\Services\LLMConfigService::class),
        );
    },

    Spora\Http\ToolController::class => static function (ContainerInterface $c): Spora\Http\ToolController {
        return new Spora\Http\ToolController(
            $c->get(AuthService::class),
            $c->get(Spora\Services\ToolConfigService::class),
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

    Spora\Console\Commands\SeedCommand::class => static function (ContainerInterface $c): Spora\Console\Commands\SeedCommand {
        return new Spora\Console\Commands\SeedCommand(
            $c->get(Database::class),
            // Closure defers AuthService (and Delight\Auth\Auth → PDO) construction until after
            // bootDatabaseConnectionOnly() has been called inside execute().
            static fn(): AuthService => $c->get(AuthService::class),
        );
    },

    OrchestratorInterface::class => static function (ContainerInterface $c): OrchestratorInterface {
        // Break the bootstrap circular dependency (Orchestrator → bus → handler → Orchestrator)
        // using a typed proxy. The proxy is wired into the bus handler first; the real
        // Orchestrator is constructed with the bus, then injected into the proxy.
        $proxy = new OrchestratorProxy();

        $bus = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                TickMessage::class => [
                    static function (TickMessage $msg) use ($proxy): void {
                        $proxy->tick($msg->taskId);
                    },
                ],
            ])),
        ]);

        $orchestrator = new Orchestrator(
            driverFactory: $c->get(Spora\Drivers\DriverFactory::class),
            bus: $bus,
            toolInstances: $c->get('tool_instances'),
            logger: $c->get(Psr\Log\LoggerInterface::class),
        );

        $proxy->setInner($orchestrator);

        return $orchestrator;
    },

    Spora\Http\TaskController::class => static function (ContainerInterface $c): Spora\Http\TaskController {
        return new Spora\Http\TaskController(
            $c->get(AuthService::class),
            $c->get(OrchestratorInterface::class),
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
];
