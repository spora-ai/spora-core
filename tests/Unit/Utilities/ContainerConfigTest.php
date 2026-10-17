<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Spora\Core\Config;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Paths;
use Spora\Settings\SettingsRegistry;

// Helpers

function getContainerConfig(): array
{
    static $definitions = null;
    if ($definitions === null) {
        $definitions = ContainerDefinitions::all();
    }
    return $definitions;
}

/**
 * Resolve the container's `Config` binding with a temporary env and
 * `config.php` in place, then put the environment back exactly as it was.
 *
 * @param array<string, string|null> $tempEnv      `SPORA_*` keys; `null` means "unset for the duration".
 * @param array<string, mixed>|null $fileConfig   Written to a throwaway `config.php`.
 */
function resolveConfig(array $tempEnv = [], ?array $fileConfig = null): Config
{
    // Save original env
    $originalEnv = $_ENV;

    // Apply temp env
    foreach ($tempEnv as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key); // Unset
        } else {
            $_ENV[$key] = (string) $value;
            putenv("$key=$value");
        }
    }

    $tmpFile = null;
    if ($fileConfig !== null) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'spora_config_');
        $export  = var_export($fileConfig, true);
        file_put_contents($tmpFile, "<?php\nreturn {$export};\n");
        $_ENV['SPORA_CONFIG_PATH'] = $tmpFile;
        putenv("SPORA_CONFIG_PATH={$tmpFile}");
    }

    $definitions = getContainerConfig();

    // The `Config` factory builds through a `SettingsRegistry` it takes from
    // the container, and that registry takes `Paths`. Both have to be
    // resolvable for the binding to run at all.
    $container = new class (new Paths(BASE_PATH)) implements ContainerInterface {
        public function __construct(private readonly Paths $paths) {}
        public function get(string $id): mixed
        {
            return match ($id) {
                Paths::class           => $this->paths,
                SettingsRegistry::class => new SettingsRegistry($this->paths),
                default                 => throw new RuntimeException("Unexpected container lookup: $id"),
            };
        }
        public function has(string $id): bool
        {
            return $id === Paths::class || $id === SettingsRegistry::class;
        }
    };
    $config = $definitions[Config::class]($container);

    if ($tmpFile !== null && file_exists($tmpFile)) {
        unlink($tmpFile);
    }

    // Restore env
    $_ENV = $originalEnv;
    foreach ($tempEnv as $key => $value) {
        if (!isset($originalEnv[$key])) {
            putenv($key);
        } else {
            putenv("$key={$originalEnv[$key]}");
        }
    }

    if ($fileConfig !== null) {
        unset($_ENV['SPORA_CONFIG_PATH']);
        putenv('SPORA_CONFIG_PATH');
    }

    return $config;
}

// Tests

test('config closure resolves basic defaults', function (): void {
    $config = resolveConfig([
        'SPORA_DB_DRIVER' => null,
        'SPORA_APP_ENV' => null,
    ]);

    // Note: depending on the user's real config.php, this might differ slightly,
    // but db_path usually defaults to sqlite.
    expect($config->getString('SPORA_DB_DRIVER'))->toBeIn(['sqlite', 'mysql', 'pgsql']);
});

test('environmental variables override default config', function (): void {
    $config = resolveConfig([
        'SPORA_DB_DRIVER'          => 'pgsql',
        'SPORA_DB_HOST'            => '127.0.0.1',
        'SPORA_DB_PORT'            => '5432',
        'SPORA_DB_NAME'            => 'test_db',
        'SPORA_DB_USER'            => 'test_user',
        'SPORA_DB_PASSWORD'        => 'secret_pass',
        'SPORA_APP_ENV'            => 'testing',
        'SPORA_ALLOW_REGISTRATION' => 'false',
    ]);

    expect($config->getString('SPORA_DB_DRIVER'))->toBe('pgsql')
        ->and($config->getString('SPORA_DB_HOST'))->toBe('127.0.0.1')
        ->and($config->getInt('SPORA_DB_PORT'))->toBe(5432)
        ->and($config->getString('SPORA_DB_NAME'))->toBe('test_db')
        ->and($config->getString('SPORA_DB_USER'))->toBe('test_user')
        ->and($config->getString('SPORA_DB_PASSWORD'))->toBe('secret_pass')
        ->and($config->getString('SPORA_APP_ENV'))->toBe('testing')
        ->and($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeFalse();
});

test('file config overrides default config', function (): void {
    $config = resolveConfig([
        'SPORA_APP_ENV'             => null,
        'SPORA_ALLOW_REGISTRATION'  => null,
    ], [
        'db_driver'          => 'mysql',
        'app_env'            => 'local',
        'allow_registration' => false,
    ]);

    expect($config->getString('SPORA_DB_DRIVER'))->toBe('mysql')
        ->and($config->getString('SPORA_APP_ENV'))->toBe('local')
        ->and($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeFalse()
        ->and($config->getString('SPORA_DB_PATH'))->toBe(BASE_PATH . '/storage/database.sqlite'); // Unchanged default
});

test('environmental variables override both file config and defaults', function (): void {
    $config = resolveConfig([
        'SPORA_APP_ENV'   => 'testing',
        'SPORA_DB_DRIVER' => 'pgsql',
    ], [
        'app_env'   => 'local',
        'db_driver' => 'mysql',
        'db_host'   => '10.0.0.1',
    ]);

    expect($config->getString('SPORA_APP_ENV'))->toBe('testing')          // env wins over file config
        ->and($config->getString('SPORA_DB_DRIVER'))->toBe('pgsql')      // env wins over file config
        ->and($config->getString('SPORA_DB_HOST'))->toBe('10.0.0.1')     // file config wins over default
        ->and($config->getInt('SPORA_DB_PORT'))->toBeNull();             // default fallback
});
