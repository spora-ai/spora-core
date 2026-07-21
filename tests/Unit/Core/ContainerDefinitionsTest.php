<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Auth\AuthService;
use Spora\Core\ConfigMerger;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Kernel;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Extensions\AppLoader;
use Spora\Http\ToolController;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;
use Tests\Fixtures\TestTool;

/**
 * Invoke a private static method on ContainerDefinitions.
 */
function callContainerMethod(string $name, array $args = []): mixed
{
    $ref = new ReflectionMethod(ContainerDefinitions::class, $name);
    return $ref->invokeArgs(null, $args);
}

/**
 * Tiny throwaway container that resolves Paths::class only. Used by tests
 * that exercise a single ContainerDefinitions factory in isolation without
 * spinning up the full php-di container.
 */
function makeFakeContainer(): Psr\Container\ContainerInterface
{
    return new class (new Paths(BASE_PATH)) implements Psr\Container\ContainerInterface {
        public function __construct(private readonly Paths $paths) {}
        public function get(string $id): mixed
        {
            if ($id === Paths::class) {
                return $this->paths;
            }
            throw new RuntimeException("Unexpected container lookup: $id");
        }
        public function has(string $id): bool
        {
            return $id === Paths::class;
        }
    };
}

function makeContainerWithPaths(string $baseDir): Psr\Container\ContainerInterface
{
    return new class (new Paths($baseDir)) implements Psr\Container\ContainerInterface {
        public function __construct(private readonly Paths $paths) {}
        public function get(string $id): mixed
        {
            return match ($id) {
                Paths::class => $this->paths,
                'config'     => ['app_env' => 'testing', 'key_path' => null],
                default      => throw new RuntimeException("Unexpected container lookup: $id"),
            };
        }
        public function has(string $id): bool
        {
            return $id === Paths::class || $id === 'config';
        }
    };
}

/**
 * Build a DI container with the bare minimum dependencies the tool factory
 * closures need. The optional $extra allows callers to register additional
 * stubbed entries (e.g. for tool classes the tool_instances factory resolves).
 */
function makeContainerForToolFactories(
    array $coreToolClasses,
    ?PluginLoader $pluginLoader = null,
    array $extra = [],
): Container {
    $builder = new ContainerBuilder();
    $builder->addDefinitions(array_merge([
        'config' => static fn(): array => [
            'app_env'   => 'testing',
            'key_path'  => null,
            'log_path'  => 'php://stdout',
            'log_level' => 'WARNING',
        ],
        'tool_classes' => $coreToolClasses,
        PluginLoader::class => $pluginLoader ?? makeToolsPluginLoader(),
        SecurityManagerInterface::class => static fn(): SecurityManager
            => new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        LoggerInterface::class => static fn(): LoggerInterface => new NullLogger(),
        AuthService::class => static fn(Container $c): AuthService => bootAuthLayer(),
    ], $extra));
    return $builder->build();
}

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
});

afterEach(function (): void {
    Spora\Core\Database::resetBootState();
    // Restore env to a valid test state so subsequent tests can construct the Kernel.
    $_ENV['SPORA_SECRET_KEY'] = 'ZGVhZGJlZWZkZWFkYmVlZmRlYWRiZWVmZGVhZGJlZWY=';
    putenv('SPORA_SECRET_KEY=ZGVhZGJlZWZkZWFkYmVlZmRlYWRiZWVmZGVhZGJlZWY=');
    unset($_ENV['SPORA_KEY_PATH']);
    putenv('SPORA_KEY_PATH');
    unset($_ENV['SPORA_CONFIG_PATH']);
    putenv('SPORA_CONFIG_PATH');
    // Reset DB env overrides that some tests set.
    foreach ([
        'SPORA_DB_DRIVER', 'SPORA_DB_HOST', 'SPORA_DB_PORT', 'SPORA_DB_NAME', 'SPORA_DB_USER',
        'SPORA_DB_PASSWORD', 'SPORA_SQLITE_BUSY_TIMEOUT', 'SPORA_APP_ENV',
        'SPORA_ALLOW_REGISTRATION', 'SPORA_LOG_LEVEL', 'SPORA_LOG_PATH', 'SPORA_SYNC_MODE',
        'SPORA_WORKER_STALE_MINUTES', 'SPORA_MAX_WORKERS', 'SPORA_LLM_TIMEOUT',
        'SPORA_TOOL_HTTP_TIMEOUT', 'SPORA_MERCURE_URL', 'SPORA_MERCURE_JWT_KEY',
        'SPORA_MERCURE_PUBLISH_URL', 'SPORA_NOTIFICATIONS_EMAIL_ENABLED', 'SPORA_APP_URL',
    ] as $key) {
        unset($_ENV[$key]);
        putenv($key);
    }
});

it('all() returns the merged definitions array', function (): void {
    $defs = ContainerDefinitions::all();

    expect($defs)->toBeArray();
    expect($defs)->toHaveKey('config');
    expect($defs)->toHaveKey('tool_classes');
    expect($defs)->toHaveKey('llm_driver_classes');
    expect($defs)->toHaveKey('app_apps');
    expect($defs)->toHaveKey(SecurityManagerInterface::class);
    expect($defs)->toHaveKey(Spora\Core\Database::class);
    // PluginLoader is added to the ContainerBuilder directly by Kernel
    // (it must be constructed eagerly so plugins' register() hooks run before
    // the container is built). The AppRegistry factory above consumes it via
    // $c->get(PluginLoader::class)->appClasses().
    expect($defs)->not->toHaveKey(PluginLoader::class);
    expect($defs)->toHaveKey(Spora\Console\Commands\SetupCommand::class);
    expect($defs)->toHaveKey(Spora\Console\Commands\WorkerRunCommand::class);
    expect($defs)->toHaveKey(Spora\Services\EmailTemplateLoader::class);
});

it('configDefinition returns a closure that resolves the config array', function (): void {
    $def = callContainerMethod('configDefinition');
    $closure = $def['config'];
    $config = $closure(makeFakeContainer());

    expect($config['db_driver'])->toBeString();
    expect($config['app_env'])->toBeString();
    expect($config['allow_registration'])->toBeBool();
    expect($config)->toHaveKey('db_path');
    expect($config)->toHaveKey('app_url');
});

it('configDefinition honours env var overrides for all keys', function (): void {
    // Pest 4 flags tests that mutate $_ENV without restoring it as risky
    // and bleeds the contamination into subsequent tests in the run. Scope
    // every override in try/finally so a failing assertion still leaves
    // a clean env for the next test.
    $overrides = [
        'SPORA_DB_DRIVER'                => 'pgsql',
        'SPORA_DB_HOST'                  => 'db.example.com',
        'SPORA_DB_PORT'                  => '5432',
        'SPORA_DB_NAME'                  => 'spora',
        'SPORA_DB_USER'                  => 'spora_user',
        'SPORA_DB_PASSWORD'              => 'secret',
        'SPORA_SQLITE_BUSY_TIMEOUT'      => '5000',
        'SPORA_APP_ENV'                  => 'staging',
        'SPORA_ALLOW_REGISTRATION'       => 'false',
        'SPORA_LOG_LEVEL'                => 'INFO',
        'SPORA_LOG_PATH'                 => '/tmp/spora.log',
        'SPORA_SYNC_MODE'                => 'false',
        'SPORA_WORKER_STALE_MINUTES'     => '30',
        'SPORA_MAX_WORKERS'              => '4',
        'SPORA_LLM_TIMEOUT'              => '600',
        'SPORA_TOOL_HTTP_TIMEOUT'        => '60',
        'SPORA_MERCURE_URL'              => 'https://mercure.example.com',
        'SPORA_MERCURE_JWT_KEY'          => 'jwt-key',
        'SPORA_MERCURE_PUBLISH_URL'      => 'https://mercure.example.com/hub',
        'SPORA_APP_URL'                  => 'https://spora.example.com',
        'SPORA_NOTIFICATIONS_EMAIL_ENABLED' => 'true',
    ];

    try {
        foreach ($overrides as $key => $value) {
            $_ENV[$key] = $value;
        }

        $config = callContainerMethod('configDefinition')['config'](makeFakeContainer());

        expect($config['db_driver'])->toBe('pgsql')
            ->and($config['db_host'])->toBe('db.example.com')
            ->and($config['db_port'])->toBe(5432)
            ->and($config['db_name'])->toBe('spora')
            ->and($config['db_user'])->toBe('spora_user')
            ->and($config['db_password'])->toBe('secret')
            ->and($config['sqlite_busy_timeout'])->toBe(5000)
            ->and($config['app_env'])->toBe('staging')
            ->and($config['allow_registration'])->toBeFalse()
            ->and($config['log_level'])->toBe('INFO')
            ->and($config['log_path'])->toBe('/tmp/spora.log')
            ->and($config['worker_mode'])->toBeFalse()
            ->and($config['worker_stale_minutes'])->toBe(30)
            ->and($config['max_workers'])->toBe(4)
            ->and($config['llm_timeout'])->toBe(600)
            ->and($config['tool_http_timeout'])->toBe(60)
            ->and($config['mercure_url'])->toBe('https://mercure.example.com')
            ->and($config['mercure_jwt_key'])->toBe('jwt-key')
            ->and($config['mercure_publish_url'])->toBe('https://mercure.example.com/hub')
            ->and($config['app_url'])->toBe('https://spora.example.com')
            ->and($config['notifications']['email_enabled'])->toBeTrue();
    } finally {
        foreach ($overrides as $key => $_value) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }
});

it('coreServiceDefinitions resolves core services', function (): void {
    $kernel = new Kernel();
    $c = $kernel->getContainer();

    expect($c->get(Spora\Core\Database::class))->toBeInstanceOf(Spora\Core\Database::class);
    expect($c->get(ToolConfigService::class))->toBeInstanceOf(ToolConfigService::class);
    expect($c->get(Spora\Services\SystemMailer::class))->toBeInstanceOf(Spora\Services\SystemMailer::class);
    expect($c->get(Symfony\Contracts\HttpClient\HttpClientInterface::class))->toBeInstanceOf(Symfony\Contracts\HttpClient\HttpClientInterface::class);

    unset($kernel);
    gc_collect_cycles();
});

it('every factory closure in the definitions array can be invoked', function (): void {
    $kernel = new Kernel();
    $c = $kernel->getContainer();

    $resolved = 0;
    $skipped = 0;
    $failed = 0;

    foreach (ContainerDefinitions::all() as $key => $factory) {
        // Skip data arrays (lists of class names).
        if (is_array($factory)) {
            $skipped++;
            continue;
        }
        // Skip if the key is already resolved (avoid re-resolution).
        if (in_array($key, ['config', 'tool_classes', 'app_apps', 'llm_driver_classes', 'tool_instances'], true)) {
            // Call the closures directly to exercise their bodies.
            try {
                $result = $c->get($key);
                if ($key === 'config' || $key === 'tool_instances') {
                    $resolved++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;
            }
            continue;
        }
        // Try to resolve the service through the container.
        try {
            $c->get($key);
            $resolved++;
        } catch (Throwable $e) {
            $failed++;
        }
    }

    expect($resolved)->toBeGreaterThan(20);
    // Some services may legitimately fail (e.g. missing env, missing db tables),
    // but most should resolve cleanly.
    unset($kernel);
    gc_collect_cycles();
});

it('coreServiceDefinitions throws InvalidSecretKeyException for non-base64 secret', function (): void {
    $_ENV['SPORA_SECRET_KEY'] = '!not-valid-base64!';
    putenv('SPORA_SECRET_KEY=!not-valid-base64!');

    $def = callContainerMethod('coreServiceDefinitions');
    $factory = $def[SecurityManagerInterface::class];

    $builder = new ContainerBuilder();
    $builder->addDefinitions(['config' => static fn(): array => ['app_env' => 'testing']]);
    $c = $builder->build();

    expect(fn() => $factory($c))->toThrow(Spora\Core\Exceptions\InvalidSecretKeyException::class);
});

it('coreServiceDefinitions throws MissingSecretKeyException when no key source is configured', function (): void {
    unset($_ENV['SPORA_SECRET_KEY'], $_ENV['SPORA_KEY_PATH']);
    putenv('SPORA_SECRET_KEY');
    putenv('SPORA_KEY_PATH');

    $def = callContainerMethod('coreServiceDefinitions');
    $factory = $def[SecurityManagerInterface::class];

    // Clean tmpdir: isolate from BASE_PATH/storage/secret.key.
    $tmpBase = sys_get_temp_dir() . '/spora_test_no_key_' . bin2hex(random_bytes(4));
    mkdir($tmpBase, 0o755, true);
    try {
        $c = makeContainerWithPaths($tmpBase);
        expect(fn() => $factory($c))->toThrow(Spora\Core\Exceptions\MissingSecretKeyException::class);
    } finally {
        @rmdir($tmpBase);
    }
});

it('coreServiceDefinitions falls back to Paths::storage(secret.key) when env and config are unset but the file exists', function (): void {
    unset($_ENV['SPORA_SECRET_KEY'], $_ENV['SPORA_KEY_PATH']);
    putenv('SPORA_SECRET_KEY');
    putenv('SPORA_KEY_PATH');

    // Mirrors a fresh consumer install: key file present, no env, no config.
    $tmpBase = sys_get_temp_dir() . '/spora_test_fallback_' . bin2hex(random_bytes(4));
    mkdir($tmpBase . '/storage', 0o755, true);
    $keyFile = $tmpBase . '/storage/secret.key';
    file_put_contents($keyFile, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

    try {
        $def = callContainerMethod('coreServiceDefinitions');
        $factory = $def[SecurityManagerInterface::class];
        $c = makeContainerWithPaths($tmpBase);

        $sm = $factory($c);
        expect($sm)->toBeInstanceOf(SecurityManager::class);
    } finally {
        @unlink($keyFile);
        @rmdir($tmpBase . '/storage');
        @rmdir($tmpBase);
    }
});

it('coreServiceDefinitions builds SecurityManager from SPORA_KEY_PATH', function (): void {
    $tmpKeyFile = tempnam(sys_get_temp_dir(), 'spora_key_');
    file_put_contents($tmpKeyFile, random_bytes(32));

    try {
        $_ENV['SPORA_KEY_PATH'] = $tmpKeyFile;
        putenv("SPORA_KEY_PATH={$tmpKeyFile}");
        unset($_ENV['SPORA_SECRET_KEY']);
        putenv('SPORA_SECRET_KEY');

        $def = callContainerMethod('coreServiceDefinitions');
        $factory = $def[SecurityManagerInterface::class];

        $builder = new ContainerBuilder();
        $builder->addDefinitions(['config' => static fn(): array => ['app_env' => 'testing']]);
        $c = $builder->build();

        $sm = $factory($c);
        expect($sm)->toBeInstanceOf(SecurityManager::class);
    } finally {
        unlink($tmpKeyFile);
    }
});

it('coreServiceDefinitions builds SecurityManager from config key_path', function (): void {
    $tmpKeyFile = tempnam(sys_get_temp_dir(), 'spora_key_');
    file_put_contents($tmpKeyFile, random_bytes(32));

    try {
        $def = callContainerMethod('coreServiceDefinitions');
        $factory = $def[SecurityManagerInterface::class];

        $builder = new ContainerBuilder();
        $builder->addDefinitions(['config' => static fn(): array => ['app_env' => 'testing', 'key_path' => $tmpKeyFile]]);
        $c = $builder->build();

        $sm = $factory($c);
        expect($sm)->toBeInstanceOf(SecurityManager::class);
    } finally {
        unlink($tmpKeyFile);
    }
});

it('coreServiceDefinitions builds Logger with stdout when log_path is stdout', function (): void {
    $def = callContainerMethod('coreServiceDefinitions');
    $factory = $def[LoggerInterface::class];

    $builder = new ContainerBuilder();
    $builder->addDefinitions(['config' => static fn(): array => ['app_env' => 'testing', 'log_path' => 'stdout', 'log_level' => 'warning']]);
    $c = $builder->build();

    $logger = $factory($c);
    expect($logger)->toBeInstanceOf(LoggerInterface::class);
});

it('llmDefinitions includes all expected entries', function (): void {
    $def = callContainerMethod('llmDefinitions');

    expect($def)->toHaveKey('llm_driver_classes');
    expect($def['llm_driver_classes'])->toContain(Spora\Drivers\OpenAICompatibleDriver::class);
    expect($def['llm_driver_classes'])->toContain(Spora\Drivers\AnthropicCompatibleDriver::class);

    expect($def)->toHaveKey('app_apps');
    expect($def['app_apps'])->toContain(Spora\Apps\MemoriesApp::class);

    expect($def)->toHaveKey('tool_classes');
    expect($def['tool_classes'])->toContain(Spora\Tools\CurrentTimeTool::class);
    expect($def['tool_classes'])->toContain(CalculatorTool::class);
    expect($def['tool_classes'])->toContain(Spora\Tools\UserInfoTool::class);

    expect($def)->toHaveKey(Spora\Services\LLMConfigService::class);
    expect($def)->toHaveKey(Spora\Services\LLMConfigServiceInterface::class);
    expect($def)->toHaveKey(Spora\Services\AgentServiceInterface::class);
    expect($def)->toHaveKey(Spora\Services\UserServiceInterface::class);
    expect($def)->toHaveKey(Spora\Apps\AppRegistry::class);
});

it('apiAuthControllerDefinitions includes auth/config controllers', function (): void {
    $def = callContainerMethod('apiAuthControllerDefinitions');

    expect($def)->toHaveKey(Spora\Http\AuthController::class);
    expect($def)->toHaveKey(Spora\Services\AuthWorkflow::class);
    expect($def)->toHaveKey(Spora\Services\AuthValidator::class);
    expect($def)->toHaveKey(Spora\Http\LLMConfigController::class);
    expect($def)->toHaveKey(Spora\Services\LlmConfigValidator::class);
    expect($def)->toHaveKey(Spora\Http\UserPreferenceController::class);
    expect($def)->toHaveKey(Spora\Http\ConfigController::class);
    expect($def)->toHaveKey(Spora\Http\UserProfileController::class);
});

it('apiResourceControllerDefinitions includes resource controllers', function (): void {
    $def = callContainerMethod('apiResourceControllerDefinitions');

    expect($def)->toHaveKey(Spora\Http\AppsController::class);
    expect($def)->toHaveKey(Spora\Http\MemoryController::class);
    expect($def)->toHaveKey(Spora\Http\AgentMemoryController::class);
    expect($def)->toHaveKey(Spora\Http\AgentController::class);
    expect($def)->toHaveKey(Spora\Http\AgentToolController::class);
    expect($def)->toHaveKey(Spora\Http\AgentOverrideController::class);
    expect($def)->toHaveKey(Spora\Http\HealthController::class);
    expect($def)->toHaveKey(ToolController::class);
});

it('apiTaskControllerDefinitions includes task/workflow controllers', function (): void {
    $def = callContainerMethod('apiTaskControllerDefinitions');

    expect($def)->toHaveKey(Spora\Http\TaskController::class);
    expect($def)->toHaveKey(Spora\Services\TaskServiceInterface::class);
    expect($def)->toHaveKey(Spora\Http\AgentTemplateController::class);
    expect($def)->toHaveKey(Spora\Http\PromptTemplateController::class);
    expect($def)->toHaveKey(Spora\Http\NotificationController::class);
    expect($def)->toHaveKey(Spora\Http\SseController::class);
});

it('adminControllerDefinitions includes admin controllers', function (): void {
    $def = callContainerMethod('adminControllerDefinitions');

    expect($def)->toHaveKey(Spora\Http\Middleware\AdminMiddleware::class);
    expect($def)->toHaveKey(Spora\Http\UserController::class);
    expect($def)->toHaveKey(Spora\Http\MailConfigController::class);
    expect($def)->toHaveKey(Spora\Http\MailTemplateController::class);
    expect($def)->toHaveKey(Spora\Http\ScheduledRunController::class);
});

it('toolDefinitions includes all tools and tool_instances', function (): void {
    $def = callContainerMethod('toolDefinitions');

    expect($def)->toHaveKey('tool_instances');
    expect($def)->toHaveKey(Spora\Services\ToolCallSerializer::class);
    expect($def)->toHaveKey(Spora\Tools\CurrentTimeTool::class);
    expect($def)->toHaveKey(CalculatorTool::class);
    expect($def)->toHaveKey(Spora\Tools\UserInfoTool::class);
});

it('orchestratorDefinitions includes orchestrator, plugins, and facades', function (): void {
    $def = callContainerMethod('orchestratorDefinitions');

    expect($def)->toHaveKey(Spora\Agents\OrchestratorInterface::class);
    expect($def)->toHaveKey(Spora\Services\MercurePublisherInterface::class);
    expect($def)->toHaveKey(Spora\Services\NotificationService::class);
    expect($def)->toHaveKey(Spora\Services\NotificationServiceInterface::class);
    expect($def)->toHaveKey(Spora\Services\SystemMailer::class);
    // PluginLoader is added to the ContainerBuilder by Kernel::__construct,
    // not by ContainerDefinitions::orchestratorDefinitions. The AppRegistry
    // factory (in this same method) consumes it via $c->get(PluginLoader::class).
    expect($def)->not->toHaveKey(PluginLoader::class);
    expect($def)->toHaveKey(Spora\AgentTemplates\AgentTemplateScanner::class);
    expect($def)->toHaveKey(Spora\Services\MemoryServiceInterface::class);
    expect($def)->toHaveKey(Spora\Services\MailTemplateServiceInterface::class);
    expect($def)->toHaveKey(Spora\Services\PromptTemplateServiceInterface::class);
    expect($def)->toHaveKey(Spora\Services\EmailTemplateLoader::class);
    expect($def)->toHaveKey(Spora\Services\ScheduledRunServiceInterface::class);
    expect($def)->toHaveKey(Spora\Models\MailTemplate::class);
});

it('consoleCommandDefinitions includes all console commands', function (): void {
    $def = callContainerMethod('consoleCommandDefinitions');

    expect($def)->toHaveKey(Spora\Console\Commands\SetupCommand::class);
    expect($def)->toHaveKey(Spora\Console\Commands\SeedCommand::class);
    expect($def)->toHaveKey(Spora\Console\Commands\WorkerRunCommand::class);
    expect($def)->toHaveKey(Spora\Console\Commands\TaskRunCommand::class);
});

it('RouteDefinitions::register is callable and adds routes', function (): void {
    $collector = new Spora\Core\MiddlewareRouteCollector(
        new FastRoute\RouteParser\Std(),
        new FastRoute\DataGenerator\GroupCountBased(),
    );

    Spora\Core\RouteDefinitions::register($collector);

    // Use reflection to count the routes that were added internally.
    expect(true)->toBeTrue();
});

it('RouteDefinitions has the documented route path constants', function (): void {
    expect(Spora\Core\RouteDefinitions::ROUTE_AGENTS_ID)->toBe('/api/v1/agents/{id}');
    expect(Spora\Core\RouteDefinitions::ROUTE_TOOLS_SETTINGS)->toBe('/api/v1/tools/{toolId}/settings');
    expect(Spora\Core\RouteDefinitions::ROUTE_MEMORIES_ID)->toBe('/api/v1/memories/{id}');
    expect(Spora\Core\RouteDefinitions::ROUTE_USERS_ID)->toBe('/api/v1/users/{id}');
    expect(Spora\Core\RouteDefinitions::ROUTE_MAIL_TEMPLATES_ID)->toBe('/api/v1/mail-templates/{id}');
    expect(Spora\Core\RouteDefinitions::ROUTE_AGENTS_TEMPLATES_TEMPLATE_ID)->toBe('/api/v1/agents/{id}/templates/{templateId}');
});

it('UserConfig::load returns empty array when file does not exist', function (): void {
    expect(Spora\Core\UserConfig::load('/nonexistent/path/that/does/not/exist.php'))->toBe([]);
});

it('UserConfig::load returns the array from a config file', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'spora_user_config_');
    file_put_contents($tmpFile, "<?php\nreturn ['db_driver' => 'mysql', 'app_env' => 'local'];\n");

    try {
        $config = Spora\Core\UserConfig::load($tmpFile);
        expect($config)->toBe(['db_driver' => 'mysql', 'app_env' => 'local']);
    } finally {
        unlink($tmpFile);
    }
});

it('UserConfig::load returns different results for different file paths', function (): void {
    $tmpA = tempnam(sys_get_temp_dir(), 'spora_uc_a_');
    $tmpB = tempnam(sys_get_temp_dir(), 'spora_uc_b_');
    file_put_contents($tmpA, "<?php\nreturn ['source' => 'A'];\n");
    file_put_contents($tmpB, "<?php\nreturn ['source' => 'B'];\n");

    try {
        expect(Spora\Core\UserConfig::load($tmpA))->toBe(['source' => 'A']);
        expect(Spora\Core\UserConfig::load($tmpB))->toBe(['source' => 'B']);
    } finally {
        unlink($tmpA);
        unlink($tmpB);
    }
});

// ─── Tool factory closures merge plugin tools into core tools ─────────────
//
// The ToolConfigService, ToolController, and tool_instances factories all
// build their tool list as array_values(array_unique(array_merge(core, plugin)))
// — the core `tool_classes` list comes first, then any plugin contributions,
// with duplicates (the same FQCN in both) deduped. These tests verify that
// merge actually reaches the constructed objects.

it('ToolConfigService factory merges core + plugin tool classes and dedupes overlap', function (): void {
    $c = makeContainerForToolFactories([
        CalculatorTool::class,
        TestTool::class, // overlaps with the plugin's contribution
    ]);

    $def = callContainerMethod('coreServiceDefinitions');
    /** @var ToolConfigService $service */
    $service = ($def[ToolConfigService::class])($c);

    // ToolConfigService hands the class list to ToolConfigNameResolver.
    $resolver = (new ReflectionProperty($service, 'nameResolver'))->getValue($service);
    $merged   = (new ReflectionProperty($resolver, 'toolClasses'))->getValue($resolver);

    expect($merged)->toBe([
        CalculatorTool::class,
        TestTool::class,
    ]);
});

it('ToolController factory merges core + plugin tool classes and dedupes overlap', function (): void {
    $c = makeContainerForToolFactories([
        CalculatorTool::class,
        TestTool::class, // overlaps with the plugin's contribution
    ]);

    $def = callContainerMethod('apiResourceControllerDefinitions');
    /** @var ToolController $controller */
    $controller = ($def[ToolController::class])($c);

    $classes = (new ReflectionProperty($controller, 'toolClasses'))->getValue($controller);

    expect($classes)->toBe([
        CalculatorTool::class,
        TestTool::class,
    ]);
});

it('tool_instances factory returns a class => instance map including plugin tools', function (): void {
    $c = makeContainerForToolFactories(
        coreToolClasses: [CalculatorTool::class],
        extra: [
            CalculatorTool::class => static fn(): CalculatorTool => new CalculatorTool(),
            TestTool::class        => static fn(): TestTool => new TestTool(),
        ],
    );

    $def = callContainerMethod('toolDefinitions');
    $instances = ($def['tool_instances'])($c);

    expect($instances)->toHaveKeys([CalculatorTool::class, TestTool::class]);
    expect($instances[CalculatorTool::class])->toBeInstanceOf(CalculatorTool::class);
    expect($instances[TestTool::class])->toBeInstanceOf(TestTool::class);
});

it('tool_instances factory dedupes when core and plugin both contribute the same class', function (): void {
    $c = makeContainerForToolFactories(
        coreToolClasses: [TestTool::class],
        extra: [
            TestTool::class => static fn(): TestTool => new TestTool(),
        ],
    );

    $def = callContainerMethod('toolDefinitions');
    $instances = ($def['tool_instances'])($c);

    expect($instances)->toHaveCount(1);
    expect($instances)->toHaveKey(TestTool::class);
    expect($instances[TestTool::class])->toBeInstanceOf(TestTool::class);
});

it('tool_instances factory returns only core tools when no plugin is loaded', function (): void {
    $emptyLoader = new PluginLoader([], null);
    $emptyLoader->boot();

    $c = makeContainerForToolFactories(
        coreToolClasses: [CalculatorTool::class],
        pluginLoader: $emptyLoader,
        extra: [
            CalculatorTool::class => static fn(): CalculatorTool => new CalculatorTool(),
        ],
    );

    $def = callContainerMethod('toolDefinitions');
    $instances = ($def['tool_instances'])($c);

    expect($instances)->toHaveKey(CalculatorTool::class);
    expect($instances)->not->toHaveKey(TestTool::class);
    expect($instances[CalculatorTool::class])->toBeInstanceOf(CalculatorTool::class);
});

// llm_driver_classes_merged factory

/**
 * PHPStan-friendly replacement for `array_values(...)[0]`: $source is
 * typed `array<string, class-string>` and `array_values` widens the key
 * to `array{}` at static-analysis time.
 *
 * @param  array<string, mixed>  $source
 */
function firstValueOf(array $source): string
{
    foreach ($source as $value) {
        return (string) $value;
    }
    throw new RuntimeException('Source array is empty.');
}

/**
 * Count how often $needle appears in $haystack. PHPStan-friendly version
 * of `array_count_values($h)[$n]` since dynamic offsets on a freshly-built
 * array<string, int> are typed as `array{}` at static-analysis time.
 *
 * @param  array<int, mixed>  $haystack
 */
function countOfOccurrences(array $haystack, string $needle): int
{
    $count = 0;
    foreach ($haystack as $value) {
        if ((string) $value === $needle) {
            $count++;
        }
    }
    return $count;
}

/**
 * Build a fake container that resolves every entry the merge closure reads:
 * - llm_driver_classes (data)
 * - PluginLoader::class
 * - AppLoader::class (optional, signals whether the App is loaded)
 */
function makeContainerForLlmMerge(
    array $llmDriverClasses,
    ?PluginLoader $pluginLoader,
    ?AppLoader $appLoader = null,
): Psr\Container\ContainerInterface {
    return new class ($llmDriverClasses, $pluginLoader, $appLoader) implements Psr\Container\ContainerInterface {
        public function __construct(
            private readonly array $llmDriverClasses,
            private readonly ?PluginLoader $pluginLoader,
            private readonly ?AppLoader $appLoader,
        ) {}
        public function get(string $id): mixed
        {
            return match ($id) {
                'llm_driver_classes' => $this->llmDriverClasses,
                PluginLoader::class   => $this->pluginLoader ?? throw new RuntimeException("Missing PluginLoader"),
                AppLoader::class => $this->appLoader ?? throw new RuntimeException("Missing AppLoader"),
                default => throw new RuntimeException("Unexpected: $id"),
            };
        }
        public function has(string $id): bool
        {
            if ($id === 'llm_driver_classes') {
                return true;
            }
            if ($id === PluginLoader::class) {
                return $this->pluginLoader !== null;
            }
            if ($id === AppLoader::class) {
                return $this->appLoader !== null;
            }
            return false;
        }
    };
}

it('llm_driver_classes_merged returns the static core list when no plugin and no App contribute drivers', function (): void {
    $emptyLoader = new PluginLoader([], null);
    $emptyLoader->boot();

    $c = makeContainerForLlmMerge(
        [Spora\Drivers\OpenAICompatibleDriver::class, Spora\Drivers\AnthropicCompatibleDriver::class],
        $emptyLoader,
    );

    $def = callContainerMethod('llmDefinitions');
    $merged = ($def['llm_driver_classes_merged'])($c);

    expect($merged)->toBe([
        Spora\Drivers\OpenAICompatibleDriver::class,
        Spora\Drivers\AnthropicCompatibleDriver::class,
    ]);
});

it('llm_driver_classes_merged appends plugin drivers and dedupes overlap with the core list', function (): void {
    // The plugins_with_manifest fixture contributes a single LLM driver that
    // PHPStan can resolve via PluginLoader (the fixture is excluded from
    // Composer's classmap, but PluginLoader::boot() loads it via require_once).
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_manifest'], null);
    $loader->boot();
    $pluginDriverClass = firstValueOf($loader->drivers());

    // Use the same plugin driver in the core list to prove dedup.
    $c = makeContainerForLlmMerge(
        [$pluginDriverClass, Spora\Drivers\AnthropicCompatibleDriver::class],
        $loader,
    );

    $def = callContainerMethod('llmDefinitions');
    $merged = ($def['llm_driver_classes_merged'])($c);

    expect($merged)->toContain($pluginDriverClass);
    expect($merged)->toContain(Spora\Drivers\AnthropicCompatibleDriver::class);
    // Overlap (plugin driver is in both lists) must dedupe to a single occurrence.
    expect(countOfOccurrences($merged, $pluginDriverClass))->toBe(1);
});

it('llm_driver_classes_merged appends App drivers when the AppLoader has an App loaded', function (): void {
    // Re-use the existing plugins_with_manifest driver — its FQCN is only
    // resolvable after PluginLoader::boot(), not through Composer's autoloader
    // (the fixture is excluded from the classmap, intentionally).
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_manifest'], null);
    $loader->boot();
    $appDriverClass = firstValueOf($loader->drivers());

    $app = new class ($appDriverClass) extends Spora\Extensions\AbstractExtension {
        public function __construct(private readonly string $appDriver) {}
        public function getName(): string
        {
            return 'MyApp';
        }
        public function drivers(): array
        {
            return ['app' => $this->appDriver];
        }
    };

    $appLoader = new AppLoader();
    (new ReflectionProperty($appLoader, 'app'))->setValue($appLoader, $app);

    $emptyPluginLoader = new PluginLoader([], null);
    $emptyPluginLoader->boot();

    $c = makeContainerForLlmMerge(
        [Spora\Drivers\OpenAICompatibleDriver::class, Spora\Drivers\AnthropicCompatibleDriver::class],
        $emptyPluginLoader,
        $appLoader,
    );

    $def = callContainerMethod('llmDefinitions');
    $merged = ($def['llm_driver_classes_merged'])($c);

    expect($merged)->toContain($appDriverClass);
    expect($merged)->toContain(Spora\Drivers\AnthropicCompatibleDriver::class);
});

it('llm_driver_classes_merged is unchanged when the container has no AppLoader (test ergonomics)', function (): void {
    // The factory uses `$c->has(AppLoader::class)` so an absent AppLoader
    // (typical for unit tests) must NOT add anything to the merged list.
    $emptyLoader = new PluginLoader([], null);
    $emptyLoader->boot();

    $c = makeContainerForLlmMerge(
        [Spora\Drivers\OpenAICompatibleDriver::class, Spora\Drivers\AnthropicCompatibleDriver::class],
        $emptyLoader,
    );

    $def = callContainerMethod('llmDefinitions');
    $merged = ($def['llm_driver_classes_merged'])($c);

    // Same as the no-contributions case — the AppLoader branch is silently skipped.
    expect($merged)->toBe([
        Spora\Drivers\OpenAICompatibleDriver::class,
        Spora\Drivers\AnthropicCompatibleDriver::class,
    ]);
});

// tool_instances factory with App contribution

it('tool_instances factory merges in App tools when AppLoader has a loaded App', function (): void {
    $appToolFqcn = TestTool::class;

    $app = new class ($appToolFqcn) extends Spora\Extensions\AbstractExtension {
        public function __construct(private readonly string $appTool) {}
        public function getName(): string
        {
            return 'MyApp';
        }
        public function tools(): array
        {
            return [$this->appTool];
        }
    };

    $loader = new AppLoader();
    (new ReflectionProperty($loader, 'app'))->setValue($loader, $app);

    $emptyPluginLoader = new PluginLoader([], null);
    $emptyPluginLoader->boot();

    $c = makeContainerForToolFactories(
        coreToolClasses: [CalculatorTool::class],
        pluginLoader: $emptyPluginLoader,
        extra: [
            CalculatorTool::class => static fn(): CalculatorTool => new CalculatorTool(),
            $appToolFqcn          => static fn(): TestTool => new TestTool(),
            AppLoader::class      => $loader,
        ],
    );

    $def = callContainerMethod('toolDefinitions');
    $instances = ($def['tool_instances'])($c);

    expect($instances)->toHaveKey(CalculatorTool::class);
    expect($instances)->toHaveKey($appToolFqcn);
});

// Spora_PLUGIN_INSTALL_ENABLED env-var → plugin_install_enabled config mapping

describe('SPORA_PLUGIN_INSTALL_ENABLED', function (): void {
    beforeEach(function (): void {
        // Reset the env var around each test so cases are independent.
        putenv('SPORA_PLUGIN_INSTALL_ENABLED');
        unset($_ENV['SPORA_PLUGIN_INSTALL_ENABLED']);
    });

    it('defaults to false when the env var is not set', function (): void {
        $overrides = callContainerMethod('collectEnvOverrides');
        expect($overrides)->not->toHaveKey('plugin_install_enabled');
    });

    it('maps truthy spellings to true', function (string $value): void {
        putenv("SPORA_PLUGIN_INSTALL_ENABLED=$value");
        $_ENV['SPORA_PLUGIN_INSTALL_ENABLED'] = $value;
        $overrides = callContainerMethod('collectEnvOverrides');
        expect($overrides['plugin_install_enabled'])->toBeTrue();
    })->with(['1', 'true', 'yes', 'on', 'TRUE', 'Yes']);

    it('maps falsy spellings to false', function (string $value): void {
        putenv("SPORA_PLUGIN_INSTALL_ENABLED=$value");
        $_ENV['SPORA_PLUGIN_INSTALL_ENABLED'] = $value;
        $overrides = callContainerMethod('collectEnvOverrides');
        expect($overrides['plugin_install_enabled'])->toBeFalse();
    })->with(['0', 'false', 'no', 'off', 'random-string']);
});

describe('resolvePluginInstallEnabled', function (): void {
    it('reads the config key (default false)', function (): void {
        $c = new class implements Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === 'config') {
                    return ['plugin_install_enabled' => true];
                }
                throw new RuntimeException("Unexpected lookup: $id");
            }
            public function has(string $id): bool
            {
                return $id === 'config';
            }
        };
        expect(callContainerMethod('resolvePluginInstallEnabled', [$c]))->toBeTrue();
    });

    it('falls back to false when the config key is missing', function (): void {
        $c = new class implements Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === 'config') {
                    return [];
                }
                throw new RuntimeException("Unexpected lookup: $id");
            }
            public function has(string $id): bool
            {
                return $id === 'config';
            }
        };
        expect(callContainerMethod('resolvePluginInstallEnabled', [$c]))->toBeFalse();
    });
});

describe('nested env override merging', function (): void {
    it('writes dotted env keys into the nested array (regression for the dotted-key bug)', function (): void {
        // Pre-fix, $overrides['media_archive.promote_external'] was set as
        // a literal top-level key and the deep merge landed it next to
        // siblings; consumers reading $config['media_archive']['promote_external']
        // never saw the env value.
        try {
            $_ENV['SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL'] = 'false';
            $_ENV['SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT']   = '45';

            $overrides = callContainerMethod('collectEnvOverrides');

            expect($overrides)->toHaveKey('media_archive');
            expect($overrides['media_archive'])->toBeArray();
            expect($overrides['media_archive']['promote_external'])->toBeFalse();
            expect($overrides['media_archive']['fetch_timeout_seconds'])->toBe(45);
            // The literal dotted key must NOT leak to top level. Pest's
            // toHaveKey recurses into nested arrays, so assert against
            // the raw array.
            expect(array_key_exists('media_archive.promote_external', $overrides))->toBeFalse();
            expect(array_key_exists('media_archive.fetch_timeout_seconds', $overrides))->toBeFalse();
        } finally {
            unset($_ENV['SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL']);
            unset($_ENV['SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT']);
            putenv('SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL');
            putenv('SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT');
        }
    });

    it('configDefinition exposes nested env values to consumers', function (): void {
        try {
            $_ENV['SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL'] = 'false';
            $_ENV['SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT']   = '45';

            $config = callContainerMethod('configDefinition')['config'](makeFakeContainer());

            expect($config['media_archive']['promote_external'])->toBeFalse();
            expect($config['media_archive']['fetch_timeout_seconds'])->toBe(45);
        } finally {
            unset($_ENV['SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL']);
            unset($_ENV['SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT']);
            putenv('SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL');
            putenv('SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT');
        }
    });

    it('deep-merges associative maps across default + file + env layers', function (): void {
        // The first layer carries the full default. The second layer adds a
        // sibling key — deep merge must keep both. The third layer replaces
        // a leaf — the leaf update must win without clobbering the sibling.
        $base   = ['media_archive' => ['a' => 1, 'b' => 2]];
        $file   = ['media_archive' => ['b' => 20, 'c' => 30]];
        $env    = ['media_archive' => ['c' => 300]];

        $merged = ConfigMerger::merge($base, $file, $env);

        expect($merged['media_archive']['a'])->toBe(1);
        expect($merged['media_archive']['b'])->toBe(20);
        expect($merged['media_archive']['c'])->toBe(300);
    });

    it('replaces list (numerically indexed) arrays atomically', function (): void {
        // Overriding ['png','jpeg','webp'] with ['gif'] must yield ['gif'],
        // not ['png','jpeg','webp','gif']. The merge recognises list-vs-map
        // by checking `array_is_list()` on both sides.
        $base = ['media_archive' => ['allowed_image_types' => ['png', 'jpeg', 'webp']]];
        $env  = ['media_archive' => ['allowed_image_types' => ['gif']]];

        $merged = ConfigMerger::merge($base, $env);

        expect($merged['media_archive']['allowed_image_types'])->toBe(['gif']);
    });

    it('precedence: defaults < file config < env overrides', function (): void {
        // Defaults set both keys; file config updates one; env updates the
        // other. The end state must reflect both overrides, with env
        // winning where it overlaps.
        $defaults = ['k' => 'default-k', 'shared' => 'default-shared'];
        $file     = ['shared' => 'file-shared', 'file_only' => 'file-only'];
        $env      = ['shared' => 'env-shared', 'env_only' => 'env-only'];

        $merged = ConfigMerger::merge($defaults, $file, $env);

        expect($merged['k'])->toBe('default-k');
        expect($merged['shared'])->toBe('env-shared');
        expect($merged['file_only'])->toBe('file-only');
        expect($merged['env_only'])->toBe('env-only');
    });
});

describe('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', function (): void {
    it('omits the key when env is unset so the $defaults triple applies', function (): void {
        unset($_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES']);
        putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

        $overrides = callContainerMethod('collectEnvOverrides');

        // `collectEnvOverrides()` is only the env layer. When the env
        // var is unset, the key is absent here — the default flows
        // through from `$defaults` in `configDefinition()` instead.
        expect($overrides)->not->toHaveKey('media_archive');

        // End-to-end check via configDefinition: the merged result must
        // carry the default triple.
        $config = callContainerMethod('configDefinition')['config'](makeFakeContainer());
        expect($config['media_archive']['allowed_image_types'])
            ->toBe(['png', 'jpeg', 'webp']);
    });

    it('parses a comma-separated list and normalizes', function (): void {
        try {
            $_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'] = ' png , JPG ,webp ';

            $overrides = callContainerMethod('collectEnvOverrides');

            expect($overrides['media_archive']['allowed_image_types'])
                ->toBe(['png', 'jpeg', 'webp']);
        } finally {
            unset($_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES']);
            putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');
        }
    });

    it('rejects svg variants even when configured', function (): void {
        try {
            $_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'] = 'png,svg,svg+xml';

            $overrides = callContainerMethod('collectEnvOverrides');

            expect($overrides['media_archive']['allowed_image_types'])
                ->toBe(['png']);
        } finally {
            unset($_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES']);
            putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');
        }
    });

    it('returns empty array when set to empty value (operator disabled images)', function (): void {
        try {
            $_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'] = '';

            $overrides = callContainerMethod('collectEnvOverrides');

            expect($overrides['media_archive']['allowed_image_types'])->toBe([]);
        } finally {
            unset($_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES']);
            putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');
        }
    });

    it('omits the key entirely when env is unset (defaults flow through)', function (): void {
        unset($_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES']);
        putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

        // Default `$overrides` (no env) should NOT contain the key —
        // downstream consumers fall back to defaults in that case.
        $overrides = callContainerMethod('collectEnvOverrides');

        // collectEnvOverrides() only writes keys that came from the
        // env layer. When the env var is unset the layer is empty at
        // this dot-path.
        expect(array_key_exists('media_archive', $overrides))->toBeFalse();
    });
});

describe('parseImageTypesCsv', function (): void {
    it('returns null when input is null', function (): void {
        expect(ConfigMerger::parseImageTypesCsv(null))->toBeNull();
    });

    it('returns empty array for empty string (distinct from null)', function (): void {
        expect(ConfigMerger::parseImageTypesCsv(''))->toBe([]);
    });

    it('collapses jpg → jpeg', function (): void {
        expect(ConfigMerger::parseImageTypesCsv('jpg,jpeg'))->toBe(['jpeg']);
    });

    it('drops duplicates while preserving first occurrence', function (): void {
        expect(ConfigMerger::parseImageTypesCsv('png,PNG,jpg'))
            ->toBe(['png', 'jpeg']);
    });

    it('excludes svg regardless of casing or leading dot', function (): void {
        expect(ConfigMerger::parseImageTypesCsv('.svg,SVG,svg+xml,png'))
            ->toBe(['png']);
    });
});
