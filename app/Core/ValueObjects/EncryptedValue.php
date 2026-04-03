<?php

declare(strict_types=1);

namespace Spora\Core\ValueObjects;

use LogicException;

/**
 * Wraps a base64-encoded Libsodium secretbox blob: base64_encode(nonce . ciphertext).
 * Nonce = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES (24 bytes), randomly generated per encryption.
 *
 * Structural safety: cannot be cast to string, preventing accidental ciphertext leakage
 * into API responses or LLM context. All callers must explicitly call SecurityManager::decrypt().
 */
final class EncryptedValue
{
    public function __construct(
        private readonly string $encoded,
    ) {}

    /** The ONLY way to retrieve the stored string — for DB persistence only. */
    public function toStorageString(): string
    {
        return $this->encoded;
    }

    public function __toString(): string
    {
        throw new LogicException(
            'EncryptedValue cannot be cast to string. Call SecurityManager::decrypt() first.',
        );
    }
}
