<?php

declare(strict_types=1);

namespace Spora\Core;

use Psr\Container\ContainerInterface;
use Spora\Core\Exceptions\InvalidSecretKeyException;
use Spora\Core\Exceptions\MissingSecretKeyException;

/**
 * Resolves the Spora secret key from env, config, or the conventional
 * `storage/secret.key` fallback, and wraps it in a {@see SecurityManager}.
 *
 * Extracted from {@see ContainerDefinitions} so the umbrella stays under
 * the SonarCloud S1448 20-method-per-class ceiling. Both helpers form
 * one cohesive "where does the key come from" subsystem, so they share
 * a class instead of moving elsewhere.
 */
final class SecurityKeyDefinitions
{
    public static function build(ContainerInterface $c): SecurityManager
    {
        $envKey = $_ENV['SPORA_SECRET_KEY'] ?? getenv('SPORA_SECRET_KEY') ?: null;
        if ($envKey !== null) {
            $decoded = base64_decode($envKey, strict: true);
            if ($decoded === false) {
                throw new InvalidSecretKeyException(
                    'SPORA_SECRET_KEY is not valid base64. Regenerate with: base64_encode(random_bytes(32))',
                );
            }
            return new SecurityManager($decoded);
        }

        $path = self::resolveKeyPath($c);
        if ($path === null) {
            throw new MissingSecretKeyException(
                'No secret key configured. Set SPORA_SECRET_KEY (base64 32 bytes) or SPORA_KEY_PATH, '
                . 'or run `php bin/spora spora:install` (or `db:seed`) to auto-generate '
                . 'storage/secret.key. Looked for: ' . $c->get(Paths::class)->storage('secret.key') . '.',
            );
        }

        return new SecurityManager($path);
    }

    private static function resolveKeyPath(ContainerInterface $c): ?string
    {
        $envKeyPath = $_ENV['SPORA_KEY_PATH'] ?? getenv('SPORA_KEY_PATH') ?: null;
        if ($envKeyPath !== null) {
            return $envKeyPath;
        }

        $configKeyPath = ($c->get('config'))['key_path'] ?? null;
        if ($configKeyPath !== null) {
            return (string) $configKeyPath;
        }

        // Conventional fallback; SecretKeyInstaller writes here on `spora:install`.
        $conventional = $c->get(Paths::class)->storage('secret.key');
        return is_file($conventional) ? $conventional : null;
    }
}
