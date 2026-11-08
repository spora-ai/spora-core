<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Exceptions\DecryptKeyMissingException;
use Spora\Core\SecurityManagerInterface;

/**
 * The key file an operator points at, resolved and read.
 *
 * A path and a raw key are told apart by the caller, not by their length: a
 * 32-character path is exactly as long as a raw key, and read as one it would
 * encrypt every stored secret with the installation's pathname.
 */
function securityKeyContainer(): DI\Container
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions(ContainerDefinitions::all());

    return $builder->build();
}

/**
 * A key file whose path is exactly as long as a raw key.
 *
 * @return array{0: string, 1: string} the directory and the file in it
 */
function securityKeyWriteThirtyTwoBytePath(string $key): array
{
    $dir = sys_get_temp_dir() . '/spora-key-' . uniqid();
    mkdir($dir);

    file_put_contents($dir . '/' . str_pad('k', 32, 'x'), $key);

    return [$dir, $dir . '/' . str_pad('k', 32, 'x')];
}

/**
 * The key path is only read when no raw key is configured, and the suite
 * configures one.
 */
function securityKeyWithoutRawKey(callable $fn): mixed
{
    $savedSecret = $_ENV['SPORA_SECRET_KEY'] ?? null;
    $savedPath = $_ENV['SPORA_KEY_PATH'] ?? null;

    unset($_ENV['SPORA_SECRET_KEY']);
    putenv('SPORA_SECRET_KEY');

    try {
        return $fn();
    } finally {
        if ($savedSecret !== null) {
            $_ENV['SPORA_SECRET_KEY'] = $savedSecret;
            putenv("SPORA_SECRET_KEY={$savedSecret}");
        }
        unset($_ENV['SPORA_KEY_PATH']);
        putenv('SPORA_KEY_PATH');
        if ($savedPath !== null) {
            $_ENV['SPORA_KEY_PATH'] = $savedPath;
            putenv("SPORA_KEY_PATH={$savedPath}");
        }
    }
}

test('a 32-byte key path is read as a file, not used as the key', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    [$dir, $absolute] = securityKeyWriteThirtyTwoBytePath($key);
    $previous = getcwd();

    try {
        // The relative path is the one the container resolves, and it is the
        // one whose length is the point.
        chdir($dir);
        $path = basename($absolute);
        expect(strlen($path))->toBe(32);

        securityKeyWithoutRawKey(function () use ($path): void {
            $_ENV['SPORA_KEY_PATH'] = $path;
            putenv("SPORA_KEY_PATH={$path}");

            $manager = securityKeyContainer()->get(SecurityManagerInterface::class);

            expect(hash('sha256', $manager->masterKey()))->toBe(hash('sha256', file_get_contents($path)));
        });
    } finally {
        chdir((string) $previous);
        @unlink($absolute);
        @rmdir($dir);
    }
});

test('a key path that names no file is refused', function (): void {
    securityKeyWithoutRawKey(function (): void {
        $missing = '/tmp/spora_no_such_key_' . uniqid() . '.key';
        $_ENV['SPORA_KEY_PATH'] = $missing;
        putenv("SPORA_KEY_PATH={$missing}");

        expect(fn() => securityKeyContainer()->get(SecurityManagerInterface::class))
            ->toThrow(DecryptKeyMissingException::class, 'not found or not readable');
    });
});
