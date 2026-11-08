<?php

declare(strict_types=1);

namespace Spora\Core;

use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\Exceptions\DecryptKeyMissingException;
use Spora\Core\Exceptions\InvalidSecretKeyException;
use Spora\Core\ValueObjects\EncryptedValue;

/**
 * Singleton. Registered via PHP-DI factory.
 * Loads master key once at construction. Fails immediately if key is invalid.
 */
final class SecurityManager implements SecurityManagerInterface
{
    private readonly string $masterKey;

    /**
     * @param string $key the raw key: exactly `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` bytes
     *
     * @throws InvalidSecretKeyException if the key is not the right length
     */
    public function __construct(string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidSecretKeyException(sprintf(
                'Secret key must be exactly %d bytes, got %d.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($key),
            ));
        }

        $this->masterKey = $key;
    }

    /**
     * The manager for a key file: the file's contents are the key.
     *
     * A path and a raw key are told apart by the caller, not by their
     * length — a 32-character path is a path, and reading it as a key would
     * encrypt every stored secret with the installation's pathname.
     *
     * @throws DecryptKeyMissingException if the file is missing, unreadable or the wrong size
     */
    public static function fromKeyFile(string $path): self
    {
        return new self(self::loadFromFile($path));
    }

    public function encrypt(string $plaintext): EncryptedValue
    {
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->masterKey);

        return new EncryptedValue(base64_encode($nonce . $ciphertext));
    }

    public function masterKey(): string
    {
        // Internal-use accessor. Components like LocalAssetStore HMAC
        // signed tokens with this; do NOT expose via any API endpoint or
        // log line. The key is loaded eagerly in the constructor, so a
        // missing-key failure has already surfaced before we get here.
        return $this->masterKey;
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

    /** @throws DecryptKeyMissingException */
    private static function loadFromFile(string $path): string
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new DecryptKeyMissingException(
                "Secret key file not found or not readable at: {$path}. Run `php bin/spora spora:install` or `php bin/spora db:seed` to bootstrap storage/secret.key.",
            );
        }

        $key = file_get_contents($path);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new DecryptKeyMissingException(
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
