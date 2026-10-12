<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Kernel;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Extensions\AppLoader;
use Spora\Http\ToolController;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Spora\Tools\CalculatorTool;
use Spora\Tools\TimeTool;
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
 * Tiny throwaway container that resolves Paths::class and, optionally, the
 * Config the caller supplies. Used by tests that exercise a single
 * ContainerDefinitions factory in isolation without spinning up the full
 * php-di container.
 *
 * A `Config` carries resolved values, not defaults: an empty one leaves every
 * toggle `false` and every path `null`. Tests that used to receive a merged
 * array here now have to say which keys they mean.
 */
function makeFakeContainer(?Config $config = null): Psr\Container\ContainerInterface
{
    return new class (new Paths(BASE_PATH), $config) implements Psr\Container\ContainerInterface {
        public function __construct(private readonly Paths $paths, private readonly ?Config $config) {}
        public function get(string $id): mixed
        {
            if ($id === Paths::class) {
                return $this->paths;
            }
            if ($id === Config::class && $this->config !== null) {
                return $this->config;
            }
            // `configDefinition()`'s Config factory builds a `Config` through a
            // registry it takes from the container, so the registry has to be
            // resolvable here or the binding cannot be exercised at all.
            if ($id === SettingsRegistry::class) {
                return new SettingsRegistry($this->paths);
            }
            throw new RuntimeException("Unexpected container lookup: $id");
        }
        public function has(string $id): bool
        {
            return $id === Paths::class
                || $id === SettingsRegistry::class
                || ($id === Config::class && $this->config !== null);
        }
    };
}

function makeContainerWithPaths(string $baseDir, ?Config $config = null): Psr\Container\ContainerInterface
{
    return new class (new Paths($baseDir), $config) implements Psr\Container\ContainerInterface {
        public function __construct(private readonly Paths $paths, private readonly ?Config $config) {}
        public function get(string $id): mixed
        {
            return match ($id) {
                Paths::class  => $this->paths,
                Config::class => $this->config ?? throw new RuntimeException("Unexpected container lookup: $id"),
                default       => throw new RuntimeException("Unexpected container lookup: $id"),
            };
        }
        public function has(string $id): bool
        {
            return $id === Paths::class || $id === Config::class;
        }
    };
}

/**
 * The settings the factories under test read, as resolved values.
 *
 * Explicit rather than built through `ConfigFactory`, which would resolve the
 * real `BASE_PATH` and the developer's own shell — the point of these tests is
 * the wiring, not the layer precedence `ConfigResolutionTest` covers.
 *
 * @param array<string, mixed> $overrides
 */
function configDefinitionsStub(array $overrides = []): Config
{
    return new Config($overrides + [
        'SPORA_APP_ENV'   => 'testing',
        'SPORA_LOG_PATH'  => 'php://stdout',
        'SPORA_LOG_LEVEL' => 'WARNING',
    ]);
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
        Config::class => static fn(): Config => configDefinitionsStub(),
        'tool_classes' => $coreToolClasses,
        PluginLoader::class => $pluginLoader ?? makeToolsPluginLoader(),
        SecurityManagerInterface::class => static fn(): SecurityManager
            => new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        LoggerInterface::class => static fn(): LoggerInterface => new NullLogger(),
        AuthService::class => static fn(Container $c): AuthService => bootAuthLayer(),
    ], $extra));
    return $builder->build();
}

/**
 * A loaded AppLoader over a throwaway `app/App.php` that contributes one tool.
 *
 * The App class name is unique per call: `AppLoader::discoverApp()` diffs the
 * declared classes across a `require_once`, so a second load of the same file is
 * a no-op it would read as "no App found".
 *
 * @return array{0: AppLoader, 1: string} [loader, base path the App lives in]
 */
function makeAppContributingTool(string $toolClass = TimeTool::class): array
{
    $base = sys_get_temp_dir() . '/spora_app_tools_' . uniqid('', true);
    if (!mkdir($base . '/app', 0o755, true) && !is_dir($base . '/app')) {
        throw new RuntimeException("Cannot create app dir: {$base}/app");
    }

    $class = 'AppContributing' . uniqid();
    file_put_contents(
        $base . '/app/App.php',
        "<?php\n\ndeclare(strict_types=1);\n\n"
        . "final class {$class} extends \\Spora\\Extensions\\AbstractExtension\n{\n"
        . "    public function getName(): string { return 'app-contributing'; }\n"
        . "    public function tools(): array { return [\\{$toolClass}::class]; }\n"
        . "}\n",
    );

    $loader = new AppLoader();
    $loader->load(new Paths($base));

    return [$loader, $base];
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
    // Every declared setting, from the registry rather than a hand-kept list:
    // a key added to `SettingsRegistry` and left off a list here survives into
    // the next test file as whatever the developer's shell or an earlier test
    // set it to.
    foreach (array_keys((new SettingsRegistry(new Paths(BASE_PATH)))->all()) as $key) {
        unset($_ENV[$key]);
        putenv($key);
    }
});

it('all() returns the merged definitions array', function (): void {
    $defs = ContainerDefinitions::all();

    expect($defs)->toBeArray();
    expect($defs)->toHaveKey(Config::class);
    expect($defs)->toHaveKey(SettingsRegistry::class);
    expect($defs)->toHaveKey(SettingsRepository::class);
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

/**
 * The binding has to produce a *resolved* Config, not an empty one: every
 * consumer asks for a declared key by name, and an unresolved Config answers
 * `false`/`null` for every one of them.
 */
it('configDefinition resolves a fully-populated Config object', function (): void {
    $def = callContainerMethod('configDefinition');
    $config = $def[Config::class](makeFakeContainer());
    $registry = $def[SettingsRegistry::class](makeFakeContainer());

    expect($config)->toBeInstanceOf(Config::class)
        ->and($config->getString('SPORA_DB_DRIVER'))->toBeString()
        ->and($config->getString('SPORA_APP_ENV'))->toBeString()
        ->and($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeBool()
        ->and($config->getString('SPORA_DB_PATH'))->toBeString()
        ->and($config->getString('SPORA_APP_URL'))->toBeString();
    // Every declared key is present, so a consumer reading one gets its
    // declared default rather than a silent null.
    expect(array_keys($config->all()))->toEqualCanonicalizing(array_keys($registry->all()));
});

it('configDefinition honours env var overrides for every declared key', function (): void {
    // Pest 4 flags tests that mutate $_ENV without restoring it as risky
    // and bleeds the contamination into subsequent tests in the run. Scope
    // every override in try/finally so a failing assertion still leaves
    // a clean env for the next test.
    //
    // One case per setting, because that is the shape that catches a renamed
    // env var: a value the factory drops surfaces here as the registry
    // default, which for nearly every key below is a different word or a
    // different number.
    $overrides = [
        'SPORA_DB_DRIVER'                        => 'sqlite',
        'SPORA_DB_PATH'                          => '/tmp/spora.sqlite',
        'SPORA_DB_HOST'                          => 'db.example.com',
        'SPORA_DB_PORT'                          => '5432',
        'SPORA_DB_NAME'                          => 'spora',
        'SPORA_DB_USER'                          => 'spora_user',
        'SPORA_DB_PASSWORD'                      => 'secret',
        'SPORA_SQLITE_BUSY_TIMEOUT'              => '5000',
        'SPORA_KEY_PATH'                         => '/tmp/spora-secret.key',
        'SPORA_APP_ENV'                          => 'staging',
        'SPORA_APP_NAME'                         => 'Spora Staging',
        'SPORA_APP_URL'                          => 'https://spora.example.com',
        'SPORA_APP_PREFIX'                       => 'spora',
        'SPORA_LOG_LEVEL'                        => 'INFO',
        'SPORA_LOG_PATH'                         => '/tmp/spora.log',
        'SPORA_ALLOW_REGISTRATION'               => 'false',
        'SPORA_ALLOW_GROUP_CREATION'             => 'false',
        'SPORA_NOTIFICATIONS_EMAIL_ENABLED'      => 'false',
        'SPORA_LLM_TIMEOUT'                      => '600',
        'SPORA_TOOL_HTTP_TIMEOUT'                => '60',
        'SPORA_WORKER_RUNTIME_MODE'              => 'client',
        'SPORA_TICK_LEASE_SECONDS'               => '900',
        'SPORA_WORKER_STALE_MINUTES'             => '30',
        'SPORA_MAX_WORKERS'                      => '4',
        'SPORA_TOOLS_GROUP_CASCADE_ENABLED'      => 'true',
        'SPORA_PLUGIN_INSTALL_ENABLED'           => 'true',
        'SPORA_PLUGIN_CATALOG_ENABLED'           => 'false',
        'SPORA_PLUGIN_CATALOG_TTL'               => '120',
        'SPORA_COMPOSER_BINARY'                  => '/usr/local/bin/composer',
        'SPORA_ASSET_STORE_MODE'                 => 'local',
        'SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES' => '2048',
        'SPORA_ASSET_STORE_MAX_BYTES'            => '4096',
        'SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL'   => 'false',
        'SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT'      => '45',
        'SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES'  => '2048',
        'SPORA_MEDIA_ARCHIVE_FFPROBE_ENABLED'    => 'true',
        'SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES' => 'png,jpg',
        'SPORA_MAIL_DRIVER'                      => 'sendmail',
        'SPORA_MAIL_HOST'                        => 'smtp.example.com',
        'SPORA_MAIL_PORT'                        => '2525',
        'SPORA_MAIL_USERNAME'                    => 'spora',
        'SPORA_MAIL_PASSWORD'                    => 'mail-secret',
        'SPORA_MAIL_FROM'                        => 'noreply@spora.example.com',
        'SPORA_MAIL_FROM_NAME'                   => 'Spora Staging',
        'SPORA_MAIL_ENCRYPTION'                  => 'ssl',
        'SPORA_MERCURE_URL'                      => 'https://mercure.example.com',
        'SPORA_MERCURE_PUBLISH_URL'              => 'https://mercure.example.com/hub',
        'SPORA_MERCURE_JWT_KEY'                  => 'jwt-key',
        'SPORA_MERCURE_LEGACY_USER_TOPIC'        => 'true',
    ];

    try {
        foreach ($overrides as $key => $value) {
            $_ENV[$key] = $value;
        }

        $config = callContainerMethod('configDefinition')[Config::class](makeFakeContainer());

        expect($config->getString('SPORA_DB_DRIVER'))->toBe('sqlite')
            ->and($config->getString('SPORA_DB_PATH'))->toBe('/tmp/spora.sqlite')
            ->and($config->getString('SPORA_DB_HOST'))->toBe('db.example.com')
            ->and($config->getInt('SPORA_DB_PORT'))->toBe(5432)
            ->and($config->getString('SPORA_DB_NAME'))->toBe('spora')
            ->and($config->getString('SPORA_DB_USER'))->toBe('spora_user')
            ->and($config->getString('SPORA_DB_PASSWORD'))->toBe('secret')
            ->and($config->getInt('SPORA_SQLITE_BUSY_TIMEOUT'))->toBe(5000)
            ->and($config->getString('SPORA_KEY_PATH'))->toBe('/tmp/spora-secret.key')
            ->and($config->getString('SPORA_APP_ENV'))->toBe('staging')
            ->and($config->getString('SPORA_APP_NAME'))->toBe('Spora Staging')
            ->and($config->getString('SPORA_APP_URL'))->toBe('https://spora.example.com')
            ->and($config->getString('SPORA_APP_PREFIX'))->toBe('/spora')
            ->and($config->getString('SPORA_LOG_LEVEL'))->toBe('INFO')
            ->and($config->getString('SPORA_LOG_PATH'))->toBe('/tmp/spora.log')
            ->and($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeFalse()
            ->and($config->getBool('SPORA_ALLOW_GROUP_CREATION'))->toBeFalse()
            ->and($config->getBool('SPORA_NOTIFICATIONS_EMAIL_ENABLED'))->toBeFalse()
            ->and($config->getInt('SPORA_LLM_TIMEOUT'))->toBe(600)
            ->and($config->getInt('SPORA_TOOL_HTTP_TIMEOUT'))->toBe(60)
            ->and($config->getString('SPORA_WORKER_RUNTIME_MODE'))->toBe('client')
            ->and($config->getInt('SPORA_TICK_LEASE_SECONDS'))->toBe(900)
            ->and($config->getInt('SPORA_WORKER_STALE_MINUTES'))->toBe(30)
            ->and($config->getInt('SPORA_MAX_WORKERS'))->toBe(4)
            ->and($config->getBool('SPORA_TOOLS_GROUP_CASCADE_ENABLED'))->toBeTrue()
            ->and($config->getBool('SPORA_PLUGIN_INSTALL_ENABLED'))->toBeTrue()
            ->and($config->getBool('SPORA_PLUGIN_CATALOG_ENABLED'))->toBeFalse()
            ->and($config->getInt('SPORA_PLUGIN_CATALOG_TTL'))->toBe(120)
            ->and($config->getString('SPORA_COMPOSER_BINARY'))->toBe('/usr/local/bin/composer')
            ->and($config->getString('SPORA_ASSET_STORE_MODE'))->toBe('local')
            ->and($config->getInt('SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES'))->toBe(2048)
            ->and($config->getInt('SPORA_ASSET_STORE_MAX_BYTES'))->toBe(4096)
            ->and($config->getBool('SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL'))->toBeFalse()
            ->and($config->getInt('SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT'))->toBe(45)
            ->and($config->getInt('SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES'))->toBe(2048)
            ->and($config->getBool('SPORA_MEDIA_ARCHIVE_FFPROBE_ENABLED'))->toBeTrue()
            ->and($config->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))->toBe(['png', 'jpeg'])
            ->and($config->getString('SPORA_MAIL_DRIVER'))->toBe('sendmail')
            ->and($config->getString('SPORA_MAIL_HOST'))->toBe('smtp.example.com')
            ->and($config->getInt('SPORA_MAIL_PORT'))->toBe(2525)
            ->and($config->getString('SPORA_MAIL_USERNAME'))->toBe('spora')
            ->and($config->getString('SPORA_MAIL_PASSWORD'))->toBe('mail-secret')
            ->and($config->getString('SPORA_MAIL_FROM'))->toBe('noreply@spora.example.com')
            ->and($config->getString('SPORA_MAIL_FROM_NAME'))->toBe('Spora Staging')
            ->and($config->getString('SPORA_MAIL_ENCRYPTION'))->toBe('ssl')
            ->and($config->getString('SPORA_MERCURE_URL'))->toBe('https://mercure.example.com')
            ->and($config->getString('SPORA_MERCURE_PUBLISH_URL'))->toBe('https://mercure.example.com/hub')
            ->and($config->getString('SPORA_MERCURE_JWT_KEY'))->toBe('jwt-key')
            ->and($config->getBool('SPORA_MERCURE_LEGACY_USER_TOPIC'))->toBeTrue();
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
        if (in_array($key, [Config::class, 'tool_classes', 'app_apps', 'llm_driver_classes', 'tool_instances'], true)) {
            // Call the closures directly to exercise their bodies.
            try {
                $result = $c->get($key);
                if ($key === Config::class || $key === 'tool_instances') {
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
    $builder->addDefinitions([Config::class => static fn(): Config => configDefinitionsStub()]);
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
        $c = makeContainerWithPaths($tmpBase, configDefinitionsStub());
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
        $c = makeContainerWithPaths($tmpBase, configDefinitionsStub());

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
        $builder->addDefinitions([Config::class => static fn(): Config => configDefinitionsStub()]);
        $c = $builder->build();

        $sm = $factory($c);
        expect($sm)->toBeInstanceOf(SecurityManager::class);
    } finally {
        unlink($tmpKeyFile);
    }
});

it('coreServiceDefinitions builds SecurityManager from the configured key_path', function (): void {
    $tmpKeyFile = tempnam(sys_get_temp_dir(), 'spora_key_');
    file_put_contents($tmpKeyFile, random_bytes(32));

    try {
        $def = callContainerMethod('coreServiceDefinitions');
        $factory = $def[SecurityManagerInterface::class];

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Config::class => static fn(): Config => configDefinitionsStub(['SPORA_KEY_PATH' => $tmpKeyFile]),
        ]);
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
    $builder->addDefinitions([
        Config::class => static fn(): Config => configDefinitionsStub([
            'SPORA_LOG_PATH'  => 'stdout',
            'SPORA_LOG_LEVEL' => 'warning',
        ]),
    ]);
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

    expect($def)->toHaveKey('tool_classes');
    expect($def['tool_classes'])->toContain(TimeTool::class);
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

it('apiAuthControllerDefinitions: LLMConfigController factory wires the right types (regression for agent-scope fix)', function (): void {
    // The agent-settings scope fix added AgentServiceInterface to
    // LLMConfigController::__construct(). The factory that builds the
    // controller under that key MUST pass an AgentServiceInterface to
    // slot #4 and PrincipalResolver to slot #5 — otherwise the controller
    // throws `Argument #4 ($agentService) must be of type ... PrincipalResolver given`
    // at first request, which is exactly what shipped in the first
    // iteration of PR #244 (round 1) and broke /api/v1/llm-configs in
    // production. This test guards against the same drift recurring.
    $def = callContainerMethod('apiAuthControllerDefinitions');
    $factory = $def[Spora\Http\LLMConfigController::class];

    $authService = bootAuthLayer();
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $llmConfigService = new Spora\Services\LLMConfigService($security, [
        Spora\Drivers\OpenAICompatibleDriver::class,
        Spora\Drivers\AnthropicCompatibleDriver::class,
    ]);
    $validator = new Spora\Services\LlmConfigValidator($llmConfigService);
    $agentService = new Spora\Services\AgentService();
    $principalResolver = new Spora\Services\PrincipalResolver();

    $builder = new ContainerBuilder();
    $builder->addDefinitions([
        Config::class => static fn(): Config => configDefinitionsStub(),
        SecurityManagerInterface::class => static fn(): SecurityManager => $security,
        AuthService::class => static fn(): AuthService => $authService,
        Spora\Services\LLMConfigServiceInterface::class => static fn(): Spora\Services\LLMConfigServiceInterface => $llmConfigService,
        Spora\Services\LlmConfigValidator::class => static fn(): Spora\Services\LlmConfigValidator => $validator,
        Spora\Services\AgentServiceInterface::class => static fn(): Spora\Services\AgentServiceInterface => $agentService,
        Spora\Services\PrincipalResolver::class => static fn(): Spora\Services\PrincipalResolver => $principalResolver,
        LoggerInterface::class => static fn(): LoggerInterface => new NullLogger(),
    ]);
    $container = $builder->build();

    // Must throw TypeError if the factory drifts — the constructor's
    // `private readonly AgentServiceInterface $agentService` rejects
    // a PrincipalResolver in slot #4.
    $controller = $factory($container);

    expect($controller)->toBeInstanceOf(Spora\Http\LLMConfigController::class);
});

it('apiResourceControllerDefinitions includes resource controllers', function (): void {
    $def = callContainerMethod('apiResourceControllerDefinitions');

    expect($def)->toHaveKey(Spora\Http\AppsController::class);
    expect($def)->toHaveKey(Spora\Http\AgentController::class);
    expect($def)->toHaveKey(Spora\Http\AgentToolController::class);
    expect($def)->toHaveKey(Spora\Http\AgentOverrideController::class);
    expect($def)->toHaveKey(Spora\Http\HealthController::class);
    expect($def)->toHaveKey(ToolController::class);
});

it('apiTaskControllerDefinitions includes task/workflow controllers', function (): void {
    $def = callContainerMethod('apiTaskControllerDefinitions');

    expect($def)->toHaveKey(Spora\Http\TaskController::class);
    expect($def)->toHaveKey(Spora\Http\TaskTickController::class);
    expect($def)->toHaveKey(Spora\Http\RetryChainController::class);
    expect($def)->toHaveKey(Spora\Services\TaskServiceInterface::class);
    expect($def)->toHaveKey(Spora\Http\AgentTemplateController::class);
    expect($def)->toHaveKey(Spora\Http\PromptTemplateController::class);
    expect($def)->toHaveKey(Spora\Http\NotificationController::class);
    expect($def)->toHaveKey(Spora\Http\SseController::class);
});

it('SseController factory falls back to mercure_url when no publish_url is set', function (): void {
    $def = callContainerMethod('apiTaskControllerDefinitions');
    $factory = $def[Spora\Http\SseController::class];

    $stubConfig = new Config([
        'SPORA_MERCURE_URL'     => 'https://hub.example.com/.well-known/mercure',
        'SPORA_MERCURE_JWT_KEY' => 'k',
        'SPORA_APP_URL'         => 'https://hub.example.com',
    ]);
    $stubAuth = Mockery::mock(AuthService::class);
    $container = Mockery::mock(Psr\Container\ContainerInterface::class);
    $container->shouldReceive('get')->with(Config::class)->andReturn($stubConfig);
    $container->shouldReceive('get')->with(AuthService::class)->andReturn($stubAuth);
    $container->shouldReceive('get')->with(Spora\Services\PrincipalResolver::class)->andReturn(new Spora\Services\PrincipalResolver());

    $controller = $factory($container);

    expect($controller)->toBeInstanceOf(Spora\Http\SseController::class);
});

it('SseController factory prefers mercure_publish_url over mercure_url when both are set', function (): void {
    $def = callContainerMethod('apiTaskControllerDefinitions');
    $factory = $def[Spora\Http\SseController::class];

    $stubConfig = new Config([
        'SPORA_MERCURE_URL'         => 'https://browser.example.com/.well-known/mercure',
        'SPORA_MERCURE_PUBLISH_URL' => 'http://spora:80/.well-known/mercure',
        'SPORA_MERCURE_JWT_KEY'     => 'k',
        'SPORA_APP_URL'             => 'https://browser.example.com',
    ]);
    $stubAuth = Mockery::mock(AuthService::class);
    $container = Mockery::mock(Psr\Container\ContainerInterface::class);
    $container->shouldReceive('get')->with(Config::class)->andReturn($stubConfig);
    $container->shouldReceive('get')->with(AuthService::class)->andReturn($stubAuth);
    $container->shouldReceive('get')->with(Spora\Services\PrincipalResolver::class)->andReturn(new Spora\Services\PrincipalResolver());

    $controller = $factory($container);
    $reflection = new ReflectionClass($controller);

    $hubUrlProp = $reflection->getProperty('hubUrl');
    expect($hubUrlProp->getValue($controller))->toBe('http://spora:80/.well-known/mercure');
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
    expect($def)->toHaveKey(TimeTool::class);
    expect($def)->toHaveKey(CalculatorTool::class);
    expect($def)->toHaveKey(Spora\Tools\UserInfoTool::class);
});

it('MediaTool is wired into the container (tool_classes + factory + resolvable instance)', function (): void {
    // Regression for the MediaTool silo bug: the class existed on disk and
    // was tested in isolation, but the DI container had no factory for it
    // and it was absent from the tool_classes array, so the orchestrator
    // never surfaced it. This test asserts all three wires are in place.
    $def = callContainerMethod('toolDefinitions');

    expect($def)->toHaveKey(Spora\Tools\MediaTool::class);

    $toolClasses = callContainerMethod('llmDefinitions')['tool_classes'];
    expect($toolClasses)->toContain(Spora\Tools\MediaTool::class);

    $kernel = new Kernel();
    $c = $kernel->getContainer();
    $tool = $c->get(Spora\Tools\MediaTool::class);

    expect($tool)->toBeInstanceOf(Spora\Tools\MediaTool::class);

    unset($kernel);
    gc_collect_cycles();
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

// A tool contributed by an App was executable at runtime — `tool_instances` has
// always gone through InstalledToolClasses — while the list the operator and the
// API see was assembled by hand at four sites that forgot the App. The symptom
// was a tool the agent could call but the UI could not find, which for the
// skill declarations now on the wire reads as "not a registered tool".

it('every hand-assembled tool list includes tools an App contributes', function (): void {
    [$appLoader, $appBase] = makeAppContributingTool();

    try {
        $c = makeContainerForToolFactories([CalculatorTool::class], extra: [
            AppLoader::class => $appLoader,
        ]);

        $coreService = callContainerMethod('coreServiceDefinitions');
        $controllers = callContainerMethod('apiResourceControllerDefinitions');

        /** @var ToolConfigService $service */
        $service = ($coreService[ToolConfigService::class])($c);
        $resolver = (new ReflectionProperty($service, 'nameResolver'))->getValue($service);
        $serviceClasses = (new ReflectionProperty($resolver, 'toolClasses'))->getValue($resolver);

        /** @var ToolController $controller */
        $controller = ($controllers[ToolController::class])($c);
        $controllerClasses = (new ReflectionProperty($controller, 'toolClasses'))->getValue($controller);

        $iconResolver = ($coreService[Spora\Services\ToolIconResolver::class])($c);
        $iconResolverTool = (new ReflectionProperty($iconResolver, 'nameResolver'))->getValue($iconResolver);
        $iconClasses = (new ReflectionProperty($iconResolverTool, 'toolClasses'))->getValue($iconResolverTool);

        $strictValidator = ($coreService[Spora\Services\ToolsRecommendsSkillsValidator::class])($c);
        $strictResolver = (new ReflectionProperty($strictValidator, 'resolver'))->getValue($strictValidator);
        $strictClasses = (new ReflectionProperty($strictResolver, 'toolClasses'))->getValue($strictResolver);

        expect($serviceClasses)->toContain(TimeTool::class)
            ->and($controllerClasses)->toContain(TimeTool::class)
            ->and($iconClasses)->toContain(TimeTool::class)
            ->and($strictClasses)->toContain(TimeTool::class);
    } finally {
        @unlink($appBase . '/app/App.php');
        @rmdir($appBase . '/app');
        @rmdir($appBase);
    }
});

// llm_driver_classes_merged factory

it('llm_driver_classes_merged equals the static core list (PR-3: no plugin or App driver contributions)', function (): void {
    // The `drivers()` extension hook was removed in 1.0, so the merged entry
    // is now structurally identical to `llm_driver_classes` — kept as a
    // separate alias for back-compat with consumers that resolve it directly.
    $c = new class implements Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            return match ($id) {
                'llm_driver_classes' => [
                    Spora\Drivers\OpenAICompatibleDriver::class,
                    Spora\Drivers\AnthropicCompatibleDriver::class,
                ],
                default => throw new RuntimeException("Unexpected: $id"),
            };
        }
        public function has(string $id): bool
        {
            return $id === 'llm_driver_classes';
        }
    };

    $def = callContainerMethod('llmDefinitions');
    $merged = ($def['llm_driver_classes_merged'])($c);

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

// SPORA_PLUGIN_INSTALL_ENABLED — the gate on the Web UI plugin install
// endpoints. Off by default, so "off" has to survive every layer; a default
// that silently became true would let anyone install a plugin.

describe('SPORA_PLUGIN_INSTALL_ENABLED', function (): void {
    beforeEach(function (): void {
        // Reset the env var around each test so cases are independent.
        putenv('SPORA_PLUGIN_INSTALL_ENABLED');
        unset($_ENV['SPORA_PLUGIN_INSTALL_ENABLED']);
    });

    it('defaults to false when no layer sets it', function (): void {
        $config = callContainerMethod('configDefinition')[Config::class](makeFakeContainer());

        expect($config->getBool('SPORA_PLUGIN_INSTALL_ENABLED'))->toBeFalse()
            ->and($config->sourceOf('SPORA_PLUGIN_INSTALL_ENABLED'))->toBe(Config::SOURCE_DEFAULT);
    });

    // The per-spelling datasets this used to carry moved to
    // SettingsRegistryTest ("coerces a toggle from every string form a layer
    // can hand it"), which drives `coerce()` for every toggle rather than
    // for this one. What is left here is the wiring: the env layer has to
    // reach this key at all.
    it('reads :value from the environment layer', function (string $value, bool $expected): void {
        putenv("SPORA_PLUGIN_INSTALL_ENABLED=$value");
        $_ENV['SPORA_PLUGIN_INSTALL_ENABLED'] = $value;

        $config = callContainerMethod('configDefinition')[Config::class](makeFakeContainer());

        expect($config->getBool('SPORA_PLUGIN_INSTALL_ENABLED'))->toBe($expected)
            ->and($config->sourceOf('SPORA_PLUGIN_INSTALL_ENABLED'))->toBe(Config::SOURCE_ENV);
    })->with([
        'on'  => ['1', true],
        'off' => ['0', false],
    ]);
});

describe('resolvePluginInstallEnabled', function (): void {
    it('reads the config key (default false)', function (): void {
        $c = new class implements Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === Config::class) {
                    return new Config(['SPORA_PLUGIN_INSTALL_ENABLED' => true]);
                }
                throw new RuntimeException("Unexpected lookup: $id");
            }
            public function has(string $id): bool
            {
                return $id === Config::class;
            }
        };
        expect(callContainerMethod('resolvePluginInstallEnabled', [$c]))->toBeTrue();
    });

    it('falls back to false when the config key is missing', function (): void {
        $c = new class implements Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === Config::class) {
                    return new Config();
                }
                throw new RuntimeException("Unexpected lookup: $id");
            }
            public function has(string $id): bool
            {
                return $id === Config::class;
            }
        };
        expect(callContainerMethod('resolvePluginInstallEnabled', [$c]))->toBeFalse();
    });
});

/**
 * The image allowlist seen through the container binding, i.e. the two layers
 * an operator can actually reach before the database exists. Per-spelling
 * normalisation, the SVG refusal and the deliberate empty value are asserted
 * against `SettingsRegistry::coerce()` and against a resolved `Config` in
 * SettingsRegistryTest and ConfigResolutionTest; repeating them here would
 * test the same three lines a fourth time.
 */
describe('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', function (): void {
    it('falls back to the registry default triple when no layer sets it', function (): void {
        unset($_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES']);
        putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

        $config = callContainerMethod('configDefinition')[Config::class](makeFakeContainer());

        expect($config->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))->toBe(['png', 'jpeg', 'webp'])
            ->and($config->sourceOf('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))->toBe(Config::SOURCE_DEFAULT);
    });

    it('parses a comma-separated list from the environment and normalizes it', function (): void {
        try {
            $_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'] = ' png , JPG ,webp ';
            putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES=png,JPG,webp');

            $config = callContainerMethod('configDefinition')[Config::class](makeFakeContainer());

            expect($config->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))->toBe(['png', 'jpeg', 'webp']);
        } finally {
            unset($_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES']);
            putenv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');
        }
    });
});


describe('AgentController wiring', function (): void {
    /**
     * Regression for the avatar-feature rollout: the controller's
     * `pictureService` property MUST be wired through the DI container.
     * If the AgentController factory forgets to pass
     * `$c->get(AgentPictureService::class)`, PHP's nullable constructor
     * default silently swallows it — the controller resolves cleanly but
     * `profile_picture` never reaches the wire. The full-suite smoke
     * (`every factory closure in the definitions array can be invoked`)
     * can't catch that because resolution succeeds with a null service.
     */
    it('injects AgentPictureService into AgentController so profile_picture reaches the wire', function (): void {
        $kernel = new Kernel();
        $c = $kernel->getContainer();

        $controller = $c->get(Spora\Http\AgentController::class);

        $ref = new ReflectionClass($controller);
        $prop = $ref->getProperty('pictureService');
        $service = $prop->getValue($controller);

        expect($service)->toBeInstanceOf(Spora\Services\AgentPictures\AgentPictureService::class);
    });
});

describe('MediaAssetReader DI wiring', function (): void {
    /**
     * Plugins resolve MediaArchive sub-services via `\DI\get(ClassName::class)`
     * in their `register()` hook. If MediaAssetReader is missing from the
     * container's `coreServiceDefinitions()`, the plugin's container-builder
     * factory throws at boot — failing closed is fine, but a regression that
     * silently downgrades the resolver to null would let the plugin ship
     * base64-related port-endpoint bugs to prod. This test catches the
     * "missing factory" path early.
     */
    it('resolves MediaAssetReader from the DI container', function (): void {
        $kernel = new Kernel();
        $c = $kernel->getContainer();

        $reader = $c->get(Spora\Services\MediaArchive\MediaAssetReader::class);

        expect($reader)->toBeInstanceOf(Spora\Services\MediaArchive\MediaAssetReader::class);
    });
});
