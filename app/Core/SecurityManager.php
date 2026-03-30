<?php

declare(strict_types=1);

namespace Spora\Core;

use RuntimeException;
use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\ValueObjects\EncryptedValue;

/**
 * Singleton. Registered via PHP-DI factory.
 * Loads master key once at construction. Fails immediately if key is invalid.
 */
final class SecurityManager implements SecurityManagerInterface
{
    private readonly string $masterKey;

    /**
     * @param string $keyOrPath  Either:
     *                           - A raw 32-byte key string (SPORA_SECRET_KEY env var decoded from base64)
     *                           - An absolute file path to secret.key (contains '/' or length > 32)
     *
     * @throws RuntimeException  If key is invalid, file is missing/unreadable/wrong size.
     */
    public function __construct(string $keyOrPath)
    {
        // A raw 32-byte binary key is exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes.
        // File paths are always a different length in practice (any meaningful absolute path
        // is either shorter or longer). We intentionally do NOT check for '/' because a
        // random binary key can contain 0x2F ('/'), which would cause a false positive.
        if (strlen($keyOrPath) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $this->masterKey = $keyOrPath;
        } else {
            $this->masterKey = $this->loadFromFile($keyOrPath);
        }
    }

    public function encrypt(string $plaintext): EncryptedValue
    {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->masterKey);

        return new EncryptedValue(base64_encode($nonce . $ciphertext));
    }

    public function decrypt(EncryptedValue $value): string
    {
        $decoded = base64_decode($value->toStorageString(), strict: true);

        if ($decoded === false) {
            throw new DecryptionFailedException('Failed to base64-decode the encrypted value.');
        }

        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $minLength   = $nonceLength + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;

        if (strlen($decoded) < $minLength) {
            throw new DecryptionFailedException('Encrypted value is too short to contain a valid nonce and MAC.');
        }

        $nonce      = substr($decoded, 0, $nonceLength);
        $ciphertext = substr($decoded, $nonceLength);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->masterKey);

        if ($plaintext === false) {
            throw new DecryptionFailedException(
                'Decryption failed: MAC mismatch or corrupted data. Check that the correct key is in use.',
            );
        }

        return $plaintext;
    }

    public function looksEncrypted(string $raw): bool
    {
        $decoded = base64_decode($raw, strict: true);

        if ($decoded === false) {
            return false;
        }

        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;

        return strlen($decoded) >= $minLength;
    }

    private function loadFromFile(string $path): string
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new RuntimeException(
                "Secret key file not found or not readable at: {$path}. Run install.php.",
            );
        }

        $key = file_get_contents($path);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                sprintf(
                    'Secret key at %s is corrupt: expected %d bytes, got %d.',
                    $path,
                    SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                    $key !== false ? strlen($key) : 0,
                ),
            );
        }

        return $key;
    }
}
