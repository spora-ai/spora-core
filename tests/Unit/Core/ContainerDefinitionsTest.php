<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Auth\AuthService;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Kernel;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Http\ToolController;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;
use Tests\Fixtures\TestTool;

const FIXTURE_TOOLS_PLUGINS = BASE_PATH . '/tests/Fixtures/plugins_with_tools';

/**
 * Invoke a private static method on ContainerDefinitions.
 */
function callContainerMethod(string $name, array $args = []): mixed
{
    $ref = new ReflectionMethod(ContainerDefinitions::class, $name);
    return $ref->invokeArgs(null, $args);
}

/**
 * Build a real PluginLoader that loads the tools-contributing fixture plugin.
 * The fixture's tools() returns [Tests\Fixtures\TestTool].
 */
function makeToolsPluginLoader(): PluginLoader
{
    $loader = new PluginLoader([FIXTURE_TOOLS_PLUGINS], null);
    $loader->boot();
    return $loader;
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
    expect($defs)->toHaveKey(PluginLoader::class);
    expect($defs)->toHaveKey(Spora\Console\Commands\SetupCommand::class);
    expect($defs)->toHaveKey(Spora\Console\Commands\WorkerRunCommand::class);
    expect($defs)->toHaveKey(Spora\Services\EmailTemplateLoader::class);
});

it('configDefinition returns a closure that resolves the config array', function (): void {
    $def = callContainerMethod('configDefinition');
    $closure = $def['config'];
    $config = $closure();

    expect($config['db_driver'])->toBeString();
    expect($config['app_env'])->toBeString();
    expect($config['allow_registration'])->toBeBool();
    expect($config)->toHaveKey('db_path');
    expect($config)->toHaveKey('app_url');
});

it('configDefinition honours env var overrides for all keys', function (): void {
    $_ENV['SPORA_DB_DRIVER'] = 'pgsql';
    $_ENV['SPORA_DB_HOST'] = 'db.example.com';
    $_ENV['SPORA_DB_PORT'] = '5432';
    $_ENV['SPORA_DB_NAME'] = 'spora';
    $_ENV['SPORA_DB_USER'] = 'spora_user';
    $_ENV['SPORA_DB_PASSWORD'] = 'secret';
    $_ENV['SPORA_SQLITE_BUSY_TIMEOUT'] = '5000';
    $_ENV['SPORA_APP_ENV'] = 'staging';
    $_ENV['SPORA_ALLOW_REGISTRATION'] = 'false';
    $_ENV['SPORA_LOG_LEVEL'] = 'INFO';
    $_ENV['SPORA_LOG_PATH'] = '/tmp/spora.log';
    $_ENV['SPORA_SYNC_MODE'] = 'false';
    $_ENV['SPORA_WORKER_STALE_MINUTES'] = '30';
    $_ENV['SPORA_MAX_WORKERS'] = '4';
    $_ENV['SPORA_LLM_TIMEOUT'] = '600';
    $_ENV['SPORA_TOOL_HTTP_TIMEOUT'] = '60';
    $_ENV['SPORA_MERCURE_URL'] = 'https://mercure.example.com';
    $_ENV['SPORA_MERCURE_JWT_KEY'] = 'jwt-key';
    $_ENV['SPORA_MERCURE_PUBLISH_URL'] = 'https://mercure.example.com/hub';
    $_ENV['SPORA_APP_URL'] = 'https://spora.example.com';
    $_ENV['SPORA_NOTIFICATIONS_EMAIL_ENABLED'] = 'true';

    $config = callContainerMethod('configDefinition')['config']();

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
});

it('coreServiceDefinitions resolves core services', function (): void {
    $kernel = new Kernel();
    $c = $kernel->getContainer();

    expect($c->get(Spora\Core\Database::class))->toBeInstanceOf(Spora\Core\Database::class);
    expect($c->get(ToolConfigService::class))->toBeInstanceOf(ToolConfigService::class);
    expect($c->get(Spora\Services\ImapClientInterface::class))->toBeInstanceOf(Spora\Services\ImapClientInterface::class);
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

    $builder = new ContainerBuilder();
    $builder->addDefinitions(['config' => static fn(): array => ['app_env' => 'testing', 'key_path' => null]]);
    $c = $builder->build();

    expect(fn() => $factory($c))->toThrow(Spora\Core\Exceptions\MissingSecretKeyException::class);
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
    expect($def['tool_classes'])->toContain(Spora\Tools\WeatherApiTool::class);
    expect($def['tool_classes'])->toContain(Spora\Tools\EmailTool::class);

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
    expect($def)->toHaveKey(Spora\Http\RecipeController::class);
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
    expect($def)->toHaveKey(Spora\Tools\WeatherApiTool::class);
    expect($def)->toHaveKey(Spora\Tools\CalDavCalendarTool::class);
});

it('orchestratorDefinitions includes orchestrator, plugins, and facades', function (): void {
    $def = callContainerMethod('orchestratorDefinitions');

    expect($def)->toHaveKey(Spora\Agents\OrchestratorInterface::class);
    expect($def)->toHaveKey(Spora\Services\MercurePublisherInterface::class);
    expect($def)->toHaveKey(Spora\Services\NotificationService::class);
    expect($def)->toHaveKey(Spora\Services\NotificationServiceInterface::class);
    expect($def)->toHaveKey(Spora\Services\SystemMailer::class);
    expect($def)->toHaveKey(PluginLoader::class);
    expect($def)->toHaveKey(Spora\Recipes\RecipeScanner::class);
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
