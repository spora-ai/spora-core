<?php

declare(strict_types=1);

use Spora\Core\SecretKeyInstaller;

function removeTree(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            removeTree($path . '/' . $entry);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function makeTempProjectRoot(string $tag): string
{
    $root = sys_get_temp_dir() . '/spora-ski-' . $tag . '-' . uniqid('', true);
    mkdir($root . '/storage', 0o755, true);
    return $root;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-ski-*') ?: [] as $dir) {
        if (is_dir($dir)) {
            removeTree($dir);
        }
    }
});

it('generates storage/secret.key with mode 0600 on a fresh project', function (): void {
    $root = makeTempProjectRoot('fresh');
    $keyPath = $root . '/storage/secret.key';

    expect(file_exists($keyPath))->toBeFalse();

    $generated = SecretKeyInstaller::ensureKeyFile($keyPath);

    expect($generated)->toBeTrue();
    expect(file_exists($keyPath))->toBeTrue();
    expect(filesize($keyPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    expect(substr(sprintf('%o', fileperms($keyPath)), -4))->toBe('0600');
});

it('reports the key as already present and skips regeneration', function (): void {
    $root = makeTempProjectRoot('existing');
    $keyPath = $root . '/storage/secret.key';
    $existing = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    file_put_contents($keyPath, $existing);

    $generated = SecretKeyInstaller::ensureKeyFile($keyPath);

    expect($generated)->toBeFalse();
    expect(file_get_contents($keyPath))->toBe($existing);
});

it('regenerates a corrupt key (wrong size)', function (): void {
    $root = makeTempProjectRoot('corrupt');
    $keyPath = $root . '/storage/secret.key';
    file_put_contents($keyPath, 'too-short');

    $generated = SecretKeyInstaller::ensureKeyFile($keyPath);

    expect($generated)->toBeTrue();
    expect(filesize($keyPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
});

it('creates the storage directory if it does not exist', function (): void {
    $root = makeTempProjectRoot('mkdir');
    $keyPath = $root . '/nested/storage/secret.key';

    expect(is_dir(dirname($keyPath)))->toBeFalse();

    SecretKeyInstaller::ensureKeyFile($keyPath);

    expect(file_exists($keyPath))->toBeTrue();
});

it('updates config.php key_path when config exists with null key_path', function (): void {
    $root = makeTempProjectRoot('update');
    $configPath = $root . '/config.php';
    file_put_contents($configPath, <<<'PHP'
<?php

return [
    'db_driver' => 'sqlite',
    'key_path'  => null,
];
PHP);

    $keyPath = $root . '/storage/secret.key';
    $updated = SecretKeyInstaller::updateConfigKeyPath($configPath, $keyPath);

    expect($updated)->toBeTrue();
    $contents = file_get_contents($configPath);
    expect($contents)->toContain("'key_path' => " . var_export($keyPath, true));
});

it('leaves config.php unchanged when key_path is already set', function (): void {
    $root = makeTempProjectRoot('preserve');
    $configPath = $root . '/config.php';
    $preset = '/etc/spora/existing.key';
    file_put_contents($configPath, <<<PHP
<?php

return [
    'key_path' => '{$preset}',
];
PHP);

    $updated = SecretKeyInstaller::updateConfigKeyPath($configPath, $root . '/storage/secret.key');

    expect($updated)->toBeFalse();
    expect(file_get_contents($configPath))->toContain("'key_path' => '{$preset}'");
});

it('returns false when config.php does not exist', function (): void {
    $root = makeTempProjectRoot('noconfig');

    $updated = SecretKeyInstaller::updateConfigKeyPath($root . '/config.php', $root . '/storage/secret.key');

    expect($updated)->toBeFalse();
});

it('is idempotent across repeated invocations', function (): void {
    $root = makeTempProjectRoot('idempotent');
    $keyPath = $root . '/storage/secret.key';

    $first = SecretKeyInstaller::ensureKeyFile($keyPath);
    $second = SecretKeyInstaller::ensureKeyFile($keyPath);

    expect($first)->toBeTrue();
    expect($second)->toBeFalse();
    expect(filesize($keyPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
});