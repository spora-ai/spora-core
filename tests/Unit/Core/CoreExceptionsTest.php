<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\Exceptions\DatabaseNotBootedException;
use Spora\Core\Exceptions\DecryptKeyMissingException;
use Spora\Core\Exceptions\InvalidSecretKeyException;
use Spora\Core\Exceptions\MissingSecretKeyException;
use Spora\Core\Exceptions\SchemaInstallFailedException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Plugins\PluginLoader;

// Helpers

function buildContainer(array $extraDefinitions = []): DI\Container
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions(array_merge(
        ContainerDefinitions::all(),
        $extraDefinitions,
    ));
    return $builder->build();
}

function withSecretKeyEnv(?string $value, callable $fn): mixed
{
    $savedKey     = $_ENV['SPORA_SECRET_KEY'] ?? null;
    $savedKeyPath = $_ENV['SPORA_KEY_PATH'] ?? null;

    if ($value === null) {
        unset($_ENV['SPORA_SECRET_KEY']);
        putenv('SPORA_SECRET_KEY');
    } else {
        $_ENV['SPORA_SECRET_KEY'] = $value;
        putenv("SPORA_SECRET_KEY={$value}");
    }
    unset($_ENV['SPORA_KEY_PATH']);
    putenv('SPORA_KEY_PATH');

    try {
        return $fn();
    } finally {
        if ($savedKey !== null) {
            $_ENV['SPORA_SECRET_KEY'] = $savedKey;
            putenv("SPORA_SECRET_KEY={$savedKey}");
        } else {
            unset($_ENV['SPORA_SECRET_KEY']);
            putenv('SPORA_SECRET_KEY');
        }
        if ($savedKeyPath !== null) {
            $_ENV['SPORA_KEY_PATH'] = $savedKeyPath;
            putenv("SPORA_KEY_PATH={$savedKeyPath}");
        }
    }
}

function withKeyEnvRestored(callable $fn): void
{
    $savedKey     = $_ENV['SPORA_SECRET_KEY'] ?? null;
    $savedKeyPath = $_ENV['SPORA_KEY_PATH'] ?? null;

    try {
        $fn();
    } finally {
        if ($savedKey !== null) {
            $_ENV['SPORA_SECRET_KEY'] = $savedKey;
            putenv("SPORA_SECRET_KEY={$savedKey}");
        } else {
            unset($_ENV['SPORA_SECRET_KEY']);
            putenv('SPORA_SECRET_KEY');
        }
        if ($savedKeyPath !== null) {
            $_ENV['SPORA_KEY_PATH'] = $savedKeyPath;
            putenv("SPORA_KEY_PATH={$savedKeyPath}");
        }
    }
}

function bootBadPrefixInstaller(): DatabaseSchemaInstaller
{
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_bad_migrations']);
    $loader->boot();

    return new DatabaseSchemaInstaller($loader, null);
}

// Inheritance assertions

test('DatabaseNotBootedException extends RuntimeException', function (): void {
    expect(new DatabaseNotBootedException('x'))->toBeInstanceOf(RuntimeException::class);
});

test('InvalidSecretKeyException extends RuntimeException', function (): void {
    expect(new InvalidSecretKeyException('x'))->toBeInstanceOf(RuntimeException::class);
});

test('MissingSecretKeyException extends RuntimeException', function (): void {
    expect(new MissingSecretKeyException('x'))->toBeInstanceOf(RuntimeException::class);
});

test('SchemaInstallFailedException extends RuntimeException', function (): void {
    expect(new SchemaInstallFailedException('x'))->toBeInstanceOf(RuntimeException::class);
});

test('DecryptKeyMissingException extends RuntimeException', function (): void {
    expect(new DecryptKeyMissingException('x'))->toBeInstanceOf(RuntimeException::class);
});

// Throw-site assertions

test('Database::getCapsule() throws DatabaseNotBootedException before boot', function (): void {
    Database::resetBootState();

    expect(fn() => Database::getCapsule())
        ->toThrow(DatabaseNotBootedException::class, 'Database not booted yet.');
})->afterEach(fn() => Database::resetBootState());

test('container throws InvalidSecretKeyException when SPORA_SECRET_KEY is not valid base64', function (): void {
    withSecretKeyEnv('!not-valid-base64!', function (): void {
        $container = buildContainer();

        expect(fn() => $container->get(SecurityManagerInterface::class))
            ->toThrow(InvalidSecretKeyException::class, 'not valid base64');
    });
});

test('container throws MissingSecretKeyException when no key source is configured', function (): void {
    withSecretKeyEnv(null, function (): void {
        // Clean tmpdir: isolate from BASE_PATH/storage/secret.key.
        $tmpBase = sys_get_temp_dir() . '/spora_test_no_key_' . bin2hex(random_bytes(4));
        mkdir($tmpBase, 0o755, true);
        try {
            $container = buildContainer([Paths::class => new Paths($tmpBase)]);
            expect(fn() => $container->get(SecurityManagerInterface::class))
                ->toThrow(MissingSecretKeyException::class, 'No secret key configured');
        } finally {
            @rmdir($tmpBase);
        }
    });
});

test('DatabaseSchemaInstaller throws SchemaInstallFailedException on misnamed plugin migration', function (): void {
    $installer = bootBadPrefixInstaller();

    expect(fn() => $installer->install())
        ->toThrow(SchemaInstallFailedException::class, 'bad-prefix-plugin_');
})->afterEach(fn() => Database::resetBootState());

test('SecurityManager throws DecryptKeyMissingException when key file is missing', function (): void {
    $missing = '/tmp/spora_does_not_exist_' . uniqid() . '.key';

    expect(fn() => new SecurityManager($missing))
        ->toThrow(DecryptKeyMissingException::class, 'not found or not readable');
});

test('SecurityManager throws DecryptKeyMissingException when key file is corrupt', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'spora_key_bad_');
    file_put_contents($tmpFile, 'tooshort');

    try {
        expect(fn() => new SecurityManager($tmpFile))
            ->toThrow(DecryptKeyMissingException::class, 'corrupt');
    } finally {
        unlink($tmpFile);
    }
});
