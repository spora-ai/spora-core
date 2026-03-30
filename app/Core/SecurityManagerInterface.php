<?php

declare(strict_types=1);

namespace Spora\Core;

use RuntimeException;
use Spora\Core\ValueObjects\EncryptedValue;

interface SecurityManagerInterface
{
    /**
     * Encrypt plaintext using the master key.
     *
     * Steps:
     *   1. $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
     *   2. $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $masterKey)
     *   3. return new EncryptedValue(base64_encode($nonce . $ciphertext))
     *
     * @throws RuntimeException  If master key is not loaded.
     */
    public function encrypt(string $plaintext): EncryptedValue;

    /**
     * Decrypt an EncryptedValue.
     *
     * Steps:
     *   1. $decoded = base64_decode($value->toStorageString())
     *   2. $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
     *   3. $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
     *   4. $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $masterKey)
     *   5. if $plain === false → throw DecryptionFailedException
     *
     * @throws Exceptions\DecryptionFailedException  MAC mismatch or corrupted data.
     * @throws RuntimeException  If master key is not loaded.
     */
    public function decrypt(EncryptedValue $value): string;

    /**
     * Structural check: does this string look like an EncryptedValue storage blob?
     * Does NOT decrypt. Checks decoded byte length >=
     * SODIUM_CRYPTO_SECRETBOX_NONCEBYTES (24) + SODIUM_CRYPTO_SECRETBOX_MACBYTES (16) + 1.
     */
    public function looksEncrypted(string $raw): bool;
}
