<?php

declare(strict_types=1);

/**
 * Integration test for bin/install.php — covers the bootstrap script that
 * delegates to Spora\Core\SecretKeyInstaller. The class itself has full
 * unit coverage in SecretKeyInstallerTest, but SonarCloud's new_coverage
 * gate is calculated per file, so the 57-line bootstrap needed its own
 * coverage.
 *
 * Strategy: run bin/install.php in-process via require so Pest's pcov
 * coverage collector tracks every line of the script. To redirect
 * BASE_PATH to a temp project root we set the Spora\Core\SPORA_BASE_PATH
 * env var (BasePathResolver honours this override before falling back
 * to reflection on the Composer ClassLoader).
 */

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

function makeTempProjectRoot(): string
{
    $root = sys_get_temp_dir() . '/spora-install-' . uniqid('', true);
    mkdir($root . '/storage', 0o755, true);
    mkdir($root . '/vendor', 0o755, true);

    // install.php loads <BASE_PATH>/vendor/autoload.php if present, so we
    // provide a stub that defers to the real spora-core autoloader for
    // Spora\ classes. This mirrors how a consumer project (which is what
    // Spora\Core\SPORA_BASE_PATH points at in production) would bootstrap.
    $realAutoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
    file_put_contents(
        $root . '/vendor/autoload.php',
        '<?php require ' . var_export($realAutoload, true) . ';' . "\n"
    );

    return $root;
}

function runInstallScript(string $projectRoot): string
{
    // Point BASE_PATH at the temp project root via env var override.
    // install.php calls BasePathResolver::resolve() at the top, so the
    // env var must be set BEFORE requiring the script.
    putenv('SPORA_BASE_PATH=' . $projectRoot);

    $script = realpath(__DIR__ . '/../../../bin/install.php');
    if ($script === false) {
        throw new RuntimeException('Cannot locate bin/install.php relative to test file');
    }

    ob_start();
    try {
        require $script;
    } finally {
        $output = ob_get_clean();
    }

    return $output === false ? '' : $output;
}

afterEach(function (): void {
    putenv('SPORA_BASE_PATH');
    foreach (glob(sys_get_temp_dir() . '/spora-install-*') ?: [] as $dir) {
        if (is_dir($dir)) {
            removeTree($dir);
        }
    }
});

it('generates storage/secret.key with mode 0600 on a fresh project', function (): void {
    $root = makeTempProjectRoot();
    $keyPath = $root . '/storage/secret.key';

    expect(file_exists($keyPath))->toBeFalse();

    $output = runInstallScript($root);

    expect($output)->toContain('Generated new secret key');
    expect($output)->toContain('chmod 0600');
    expect($output)->toContain('Done. Next step');
    expect(file_exists($keyPath))->toBeTrue();
    expect(filesize($keyPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    expect(substr(sprintf('%o', fileperms($keyPath)), -4))->toBe('0600');
});

it('reports the key as already present and skips regeneration', function (): void {
    $root = makeTempProjectRoot();
    $keyPath = $root . '/storage/secret.key';
    $existing = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    file_put_contents($keyPath, $existing);

    $output = runInstallScript($root);

    expect($output)->toContain('already present');
    expect($output)->not->toContain('Generated new secret key');
    expect(file_get_contents($keyPath))->toBe($existing);
});

it('updates config.php key_path when config exists with null key_path', function (): void {
    $root = makeTempProjectRoot();
    file_put_contents($root . '/config.php', <<<'PHP'
<?php

return [
    'db_driver' => 'sqlite',
    'key_path'  => null,
];
PHP);

    $output = runInstallScript($root);

    expect($output)->toContain('Updated');
    $contents = file_get_contents($root . '/config.php');
    // BASE_PATH is resolved via realpath() inside BasePathResolver, which on
    // macOS rewrites /var/folders/... → /private/var/folders/.... Compare
    // against the realpath-resolved key path.
    expect($contents)->toContain("'key_path' => " . var_export(realpath($root) . '/storage/secret.key', true));
});

it('reports config as unchanged when key_path is already set', function (): void {
    $root = makeTempProjectRoot();
    $preset = '/etc/spora/existing.key';
    file_put_contents($root . '/config.php', <<<PHP
<?php

return [
    'key_path' => '{$preset}',
];
PHP);

    $output = runInstallScript($root);

    expect($output)->toContain('Leaving config[\'key_path\'] unchanged');
    expect(file_get_contents($root . '/config.php'))->toContain("'key_path' => '{$preset}'");
});

it('is idempotent across repeated invocations', function (): void {
    $root = makeTempProjectRoot();

    runInstallScript($root);
    $output = runInstallScript($root);

    expect($output)->toContain('already present');
});