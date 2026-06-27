#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Spora installer helper.
 *
 * Bootstraps the encryption key (storage/secret.key) and points
 * config['key_path'] at it, so a fresh checkout can run `bin/spora
 * spora:install` without a MissingSecretKeyException.
 *
 * BASE_PATH is derived from the Composer autoloader's location (same trick
 * as bin/spora) so this script works whether invoked from
 * vendor/spora-ai/spora-core/bin/install.php or from a top-level
 * bin/install.php in the consumer project.
 *
 * The actual logic lives in Spora\Core\SecretKeyInstaller so it can be
 * unit-tested without spawning a subprocess.
 */

require_once __DIR__ . '/../app/Core/BasePathResolver.php';

$basePath = Spora\Core\BasePathResolver::resolve();
if ($basePath === null) {
    $basePath = dirname(__DIR__, 3);
    fwrite(STDERR, "Warning: Composer autoloader not detected; falling back to {$basePath}\n");
}

$autoload = $basePath . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (! class_exists(Spora\Core\SecretKeyInstaller::class)) {
    fwrite(STDERR, "Error: Spora\\Core\\SecretKeyInstaller not autoloaded. Run `composer install` first.\n");
    exit(1);
}

$keyPath    = $basePath . '/storage/secret.key';
$configPath = $basePath . '/config.php';

$generated = Spora\Core\SecretKeyInstaller::ensureKeyFile($keyPath);
if ($generated) {
    fwrite(STDOUT, "Generated new secret key at {$keyPath} (chmod 0600)\n");
} else {
    fwrite(STDOUT, "Secret key already present at {$keyPath}\n");
}

$updated = Spora\Core\SecretKeyInstaller::updateConfigKeyPath($configPath, $keyPath);
if ($updated) {
    fwrite(STDOUT, "Updated {$configPath} with key_path => {$keyPath}\n");
} else {
    fwrite(STDOUT, "Leaving config['key_path'] unchanged.\n");
}

fwrite(STDOUT, "\nDone. Next step: php bin/spora spora:install\n");
