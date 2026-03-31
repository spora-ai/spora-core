<?php

declare(strict_types=1);


// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getContainerConfig(): array
{
    static $definitions = null;
    if ($definitions === null) {
        $definitions = require BASE_PATH . '/app/Core/container.php';
    }
    return $definitions;
}

function resolveConfig(array $tempEnv = [], ?array $fileConfig = null): array
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

    $definitions   = getContainerConfig();
    $configClosure = $definitions['config'];
    $config        = $configClosure();

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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('config closure resolves basic defaults', function (): void {
    $config = resolveConfig([
        'SPORA_DB_DRIVER' => null,
        'SPORA_APP_ENV' => null,
    ]);

    // Note: depending on the user's real config.php, this might differ slightly,
    // but db_path usually defaults to sqlite.
    expect($config['db_driver'])->toBeIn(['sqlite', 'mysql', 'pgsql']);
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

    expect($config['db_driver'])->toBe('pgsql')
        ->and($config['db_host'])->toBe('127.0.0.1')
        ->and($config['db_port'])->toBe(5432)
        ->and($config['db_name'])->toBe('test_db')
        ->and($config['db_user'])->toBe('test_user')
        ->and($config['db_password'])->toBe('secret_pass')
        ->and($config['app_env'])->toBe('testing')
        ->and($config['allow_registration'])->toBeFalse();
});

test('file config overrides default config', function (): void {
    $config = resolveConfig([], [
        'db_driver'          => 'mysql',
        'app_env'            => 'local',
        'allow_registration' => false,
    ]);

    expect($config['db_driver'])->toBe('mysql')
        ->and($config['app_env'])->toBe('local')
        ->and($config['allow_registration'])->toBeFalse()
        ->and($config['db_path'])->toBe(BASE_PATH . '/storage/database.sqlite'); // Unchanged default
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

    expect($config['app_env'])->toBe('testing')     // env wins over file config
        ->and($config['db_driver'])->toBe('pgsql')  // env wins over file config
        ->and($config['db_host'])->toBe('10.0.0.1') // file config wins over default
        ->and($config['db_port'])->toBeNull();      // default fallback
});
