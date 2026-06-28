<?php

declare(strict_types=1);

namespace Spora\Core;

/**
 * Bootstraps the encryption key on a fresh checkout.
 *
 * Generates storage/secret.key (32 random bytes, chmod 0600) and points
 * config['key_path'] at it. Used by InstallCommand and SeedCommand on
 * their first run; safe to call directly from tests with explicit paths.
 *
 * Idempotent:
 *  - Skips the file write if it already exists and is the right size.
 *  - Only rewrites config.php when 'key_path' is currently `null`.
 *
 * Does not depend on the Composer autoloader or any global state, so it
 * works whether BASE_PATH points at the operator's project root or at
 * vendor/spora-ai/spora-core.
 */
final class SecretKeyInstaller
{
    private const KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES; // 32

    /**
     * Write a 32-byte secret key to $keyPath. Returns true if a new key was
     * generated, false if the existing key was kept.
     *
     * Returns false if any of mkdir / file_put_contents / chmod fail so
     * callers can surface a real error instead of silently booting with a
     * missing key file.
     */
    public static function ensureKeyFile(string $keyPath): bool
    {
        $storageDir = dirname($keyPath);
        if (! is_dir($storageDir) && ! mkdir($storageDir, 0o755, true) && ! is_dir($storageDir)) {
            return false;
        }

        if (is_file($keyPath)) {
            $existing = file_get_contents($keyPath);
            if ($existing !== false && strlen($existing) === self::KEY_BYTES) {
                return false;
            }
        }

        return self::writeFreshKey($keyPath);
    }

    /**
     * Write a freshly generated key to disk and lock it down to 0600.
     * Returns true on success, false if any step fails (and rolls back
     * any partially-written file so we never leave a key behind without
     * the correct permissions).
     */
    private static function writeFreshKey(string $keyPath): bool
    {
        if (file_put_contents($keyPath, random_bytes(self::KEY_BYTES)) === false) {
            return false;
        }

        if (! chmod($keyPath, 0o600)) {
            @unlink($keyPath);
            return false;
        }

        return true;
    }

    /**
     * Update config.php so config['key_path'] points at $keyPath. Returns
     * true if the file was modified, false otherwise.
     *
     * Only replaces an existing 'key_path' => null, entry. Existing
     * non-null values are preserved (operators may have set their own path
     * via config.php or the SPORA_KEY_PATH env var).
     */
    public static function updateConfigKeyPath(string $configPath, string $keyPath): bool
    {
        if (! is_file($configPath)) {
            return false;
        }

        $source = file_get_contents($configPath);
        if ($source === false) {
            return false;
        }

        $pattern     = "/'key_path'\s*=>\s*null\s*,?\s*\n/";
        $replacement = "'key_path' => " . var_export($keyPath, true) . ",\n";

        $updated = preg_replace($pattern, $replacement, $source, 1);

        return $updated !== null
            && $updated !== $source
            && file_put_contents($configPath, $updated) !== false;
    }
}
