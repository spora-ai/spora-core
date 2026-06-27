<?php

declare(strict_types=1);

use Spora\Core\SecretKeyInstaller;

function makeTempKeyPath(): string
{
    return sys_get_temp_dir() . '/spora-secret-key-' . uniqid('', true);
}

function makeTempConfigPath(): string
{
    return sys_get_temp_dir() . '/spora-config-' . uniqid('', true) . '.php';
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-secret-key-*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob(sys_get_temp_dir() . '/spora-config-*.php') ?: [] as $file) {
        @unlink($file);
    }
});

it('writes a 32-byte key file with mode 0600 when missing', function (): void {
    $path = makeTempKeyPath();

    $generated = SecretKeyInstaller::ensureKeyFile($path);

    expect($generated)->toBeTrue();
    expect(is_file($path))->toBeTrue();
    expect(filesize($path))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');
});

it('returns false and leaves an existing valid key untouched', function (): void {
    $path    = makeTempKeyPath();
    $bytes   = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    file_put_contents($path, $bytes);

    $generated = SecretKeyInstaller::ensureKeyFile($path);

    expect($generated)->toBeFalse();
    expect(file_get_contents($path))->toBe($bytes);
});

it('regenerates a key when the existing file is the wrong size', function (): void {
    $path  = makeTempKeyPath();
    file_put_contents($path, 'too-short');

    $generated = SecretKeyInstaller::ensureKeyFile($path);

    expect($generated)->toBeTrue();
    expect(filesize($path))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
});

it('updates config.php to point key_path at the absolute key path', function (): void {
    $configPath = makeTempConfigPath();
    $keyPath    = makeTempKeyPath();

    file_put_contents($configPath, <<<'PHP'
<?php

return [
    'db_driver' => 'sqlite',
    'key_path'  => null,
];
PHP);

    $updated = SecretKeyInstaller::updateConfigKeyPath($configPath, $keyPath);

    expect($updated)->toBeTrue();
    $contents = file_get_contents($configPath);
    expect($contents)->toContain("'key_path' => " . var_export($keyPath, true) . ',');
});

it('does not overwrite a non-null key_path in config.php', function (): void {
    $configPath = makeTempConfigPath();
    $existing   = '/etc/spora/my.key';
    file_put_contents($configPath, <<<PHP
<?php

return [
    'key_path' => '{$existing}',
];
PHP);

    $newKey    = makeTempKeyPath();
    $updated   = SecretKeyInstaller::updateConfigKeyPath($configPath, $newKey);

    expect($updated)->toBeFalse();
    expect(file_get_contents($configPath))->toContain("'key_path' => '{$existing}'");
    expect(file_get_contents($configPath))->not->toContain($newKey);
});

it('returns false when config.php does not exist', function (): void {
    $updated = SecretKeyInstaller::updateConfigKeyPath(makeTempConfigPath(), makeTempKeyPath());

    expect($updated)->toBeFalse();
});