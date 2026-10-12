<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Core\ConfigFactory;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;

function configResolutionRegistry(): SettingsRegistry
{
    return new SettingsRegistry(new Paths(BASE_PATH));
}

function configResolutionRepository(SettingsRegistry $registry): SettingsRepository
{
    // A throwaway 32-byte key: this file is about which layer wins, not about
    // the cipher, and the row it writes is a non-secret toggle.
    return new SettingsRepository(new SecurityManager(str_repeat('c', 32)), $registry);
}

function configResolutionBuildConfig(SettingsRegistry $registry): Config
{
    return (new ConfigFactory($registry, new Paths(BASE_PATH)))->create();
}

function configResolutionSetEnv(string $key, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        return;
    }

    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

/**
 * Every setting key plus the `config.php` redirect, cleared for the duration
 * of a test so an operator's own shell cannot decide which layer wins. Left in
 * place it would make the suite pass locally and fail in CI.
 */
function configResolutionManagedEnvKeys(): array
{
    return array_merge(['SPORA_CONFIG_PATH'], array_keys(configResolutionRegistry()->all()));
}

/**
 * `UserConfig::load()` memoises per path behind a `require_once`, so a second
 * test reusing one path would read the first test's file. The path has to be
 * new every time.
 */
function configResolutionUseConfigFile(array $values): string
{
    $path = sprintf('%s/spora-config-resolution-%s.php', sys_get_temp_dir(), uniqid());
    file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
    configResolutionSetEnv('SPORA_CONFIG_PATH', $path);

    return $path;
}

beforeEach(function (): void {
    $snapshot = [];
    foreach (configResolutionManagedEnvKeys() as $key) {
        $snapshot[$key] = [
            'env'    => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'getenv' => getenv($key),
        ];
        configResolutionSetEnv($key, null);
    }
    $GLOBALS['__config_resolution_env'] = $snapshot;
});

afterEach(function (): void {
    foreach ((array) ($GLOBALS['__config_resolution_env'] ?? []) as $key => $snapshot) {
        if ($snapshot['env'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $snapshot['env'];
        }
        if ($snapshot['server'] === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $snapshot['server'];
        }
        if ($snapshot['getenv'] === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $snapshot['getenv']);
        }
    }
    unset($GLOBALS['__config_resolution_env']);

    foreach (glob(sys_get_temp_dir() . '/spora-config-resolution-*.php') ?: [] as $file) {
        @unlink($file);
    }
});

it('uses the registry default when no layer sets the key', function (): void {
    configResolutionUseConfigFile([]);

    $config = configResolutionBuildConfig(configResolutionRegistry());

    expect($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeTrue()
        ->and($config->sourceOf('SPORA_ALLOW_REGISTRATION'))->toBe(Config::SOURCE_DEFAULT);
});

it('lets config.php override the registry default', function (): void {
    configResolutionUseConfigFile(['SPORA_ALLOW_REGISTRATION' => false]);

    $config = configResolutionBuildConfig(configResolutionRegistry());

    expect($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeFalse()
        ->and($config->sourceOf('SPORA_ALLOW_REGISTRATION'))->toBe(Config::SOURCE_CONFIG_FILE);
});

it('lets the environment override config.php', function (): void {
    configResolutionUseConfigFile(['SPORA_ALLOW_REGISTRATION' => true]);
    configResolutionSetEnv('SPORA_ALLOW_REGISTRATION', 'false');

    $config = configResolutionBuildConfig(configResolutionRegistry());

    expect($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeFalse()
        ->and($config->sourceOf('SPORA_ALLOW_REGISTRATION'))->toBe(Config::SOURCE_ENV);
});

it('lets a stored row override the environment', function (): void {
    configResolutionUseConfigFile([]);
    configResolutionSetEnv('SPORA_ALLOW_REGISTRATION', 'false');

    $registry = configResolutionRegistry();
    $repository = configResolutionRepository($registry);
    $repository->put($registry->get('SPORA_ALLOW_REGISTRATION'), true, null);

    $config = configResolutionBuildConfig($registry);
    $config->applySettingsOverlay($repository, $registry);

    expect($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeTrue()
        ->and($config->sourceOf('SPORA_ALLOW_REGISTRATION'))->toBe(Config::SOURCE_DATABASE);
});

/**
 * Two assertions because the two states look identical from the outside: a
 * `Config` that silently ignored the overlay would still answer the first
 * one correctly.
 */
it('keeps a stored row invisible before the overlay and visible after it', function (): void {
    configResolutionUseConfigFile([]);

    $registry = configResolutionRegistry();
    $repository = configResolutionRepository($registry);
    $repository->put($registry->get('SPORA_ALLOW_REGISTRATION'), false, null);

    $config = configResolutionBuildConfig($registry);

    expect($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeTrue()
        ->and($config->sourceOf('SPORA_ALLOW_REGISTRATION'))->toBe(Config::SOURCE_DEFAULT);

    $config->applySettingsOverlay($repository, $registry);

    expect($config->getBool('SPORA_ALLOW_REGISTRATION'))->toBeFalse()
        ->and($config->sourceOf('SPORA_ALLOW_REGISTRATION'))->toBe(Config::SOURCE_DATABASE);
});

it('ignores a stored row for a key the registry marks boot only', function (): void {
    configResolutionUseConfigFile([]);

    $registry = configResolutionRegistry();
    $repository = configResolutionRepository($registry);
    $repository->put($registry->get('SPORA_DB_HOST'), 'db.stored.example.com', null);

    $config = configResolutionBuildConfig($registry);
    $config->applySettingsOverlay($repository, $registry);

    expect($config->get('SPORA_DB_HOST'))->toBeNull()
        ->and($config->sourceOf('SPORA_DB_HOST'))->toBe(Config::SOURCE_DEFAULT);
});

/**
 * `media_archive.fetch_timeout_seconds` resolves to
 * `SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT` — without the suffix. That is deliberate:
 * the env var that has always overridden it does not carry one, so an
 * operator with the env var set must not see it silently ignored.
 */
it('resolves a deployed legacy config.php to the current keys', function (): void {
    configResolutionUseConfigFile([
        'db_driver'     => 'mysql',
        'media_archive' => ['fetch_timeout_seconds' => 45],
        'mail'          => ['host' => 'smtp.example.com'],
    ]);

    $config = configResolutionBuildConfig(configResolutionRegistry());

    expect($config->getString('SPORA_DB_DRIVER'))->toBe('mysql')
        ->and($config->getInt('SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT'))->toBe(45)
        ->and($config->getString('SPORA_MAIL_HOST'))->toBe('smtp.example.com')
        ->and($config->sourceOf('SPORA_MAIL_HOST'))->toBe(Config::SOURCE_CONFIG_FILE);
});

it('resolves a config.php already written in the flat SPORA_ shape', function (): void {
    configResolutionUseConfigFile([
        'SPORA_MAIL_HOST'    => 'flat.example.com',
        'SPORA_LLM_TIMEOUT'  => 45,
    ]);

    $config = configResolutionBuildConfig(configResolutionRegistry());

    expect($config->getString('SPORA_MAIL_HOST'))->toBe('flat.example.com')
        ->and($config->getInt('SPORA_LLM_TIMEOUT'))->toBe(45);
});

it('resolves a key present in both config.php shapes to the flat one', function (): void {
    configResolutionUseConfigFile([
        'mail'            => ['host' => 'legacy.example.com'],
        'SPORA_MAIL_HOST' => 'flat.example.com',
    ]);

    expect(configResolutionBuildConfig(configResolutionRegistry())->getString('SPORA_MAIL_HOST'))
        ->toBe('flat.example.com');
});

it('maps only to keys the registry declares', function (): void {
    $undeclared = array_values(array_diff(
        array_values(ConfigFactory::legacyKeyMap()),
        array_keys(configResolutionRegistry()->all()),
    ));

    expect($undeclared)->toBe([]);
});

it('maps no two legacy config.php paths to the same key', function (): void {
    $collisions = array_keys(array_filter(
        array_count_values(array_values(ConfigFactory::legacyKeyMap())),
        static fn (int $occurrences): bool => $occurrences > 1,
    ));

    expect($collisions)->toBe([]);
});

/**
 * The contract `ConfigFactory::envValues()` states for itself: an env var set
 * to the empty string reads as unset, so a blanked-out
 * `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES=` falls back to the default instead
 * of disabling image uploads.
 *
 * Known failure. `$raw = $_ENV[$key] ?? (getenv($key) ?: null)` short-circuits
 * on `$_ENV` before the `?: null` it relies on is reached, and
 * `Kernel::loadDotEnv()` uses `Dotenv::createImmutable()`, whose default
 * `EnvConstAdapter` writes `.env` into `$_ENV`. So the variable this contract
 * is written about is exactly the one that never reaches the `?:`. Today
 * `$_ENV['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'] = ''` resolves to `[]` —
 * no image type allowed — from the `config.php` file's comment on
 * {@see \Spora\Core\ConfigFactory}.
 */
it('treats an env var set to the empty string as unset', function (): void {
    configResolutionUseConfigFile([]);
    configResolutionSetEnv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', '');

    $config = configResolutionBuildConfig(configResolutionRegistry());

    expect($config->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))->toBe(['png', 'jpeg', 'webp'])
        ->and($config->sourceOf('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))->toBe(Config::SOURCE_DEFAULT);
});

/**
 * The SVG exclusion has to hold on the layers an operator actually types
 * into. It is asserted on `coerce()` in the registry test; these two are the
 * same control seen through a resolved `Config`.
 */
it('still refuses SVG when the image allowlist arrives from the environment', function (): void {
    configResolutionUseConfigFile([]);
    configResolutionSetEnv('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES', 'png,svg,jpg');

    expect(configResolutionBuildConfig(configResolutionRegistry())->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))
        ->toBe(['png', 'jpeg']);
});

it('still refuses SVG when the image allowlist arrives from config.php', function (): void {
    configResolutionUseConfigFile(['SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES' => 'png,SVG,.svg']);

    expect(configResolutionBuildConfig(configResolutionRegistry())->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES'))
        ->toBe(['png']);
});