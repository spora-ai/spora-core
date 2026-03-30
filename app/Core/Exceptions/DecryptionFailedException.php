<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when sodium_crypto_secretbox_open() returns false.
 * Indicates wrong key, tampered ciphertext, or corrupted storage.
 * ToolConfigService catches this per-field, returns null for that field, and logs.
 */
final class DecryptionFailedException extends RuntimeException {}
