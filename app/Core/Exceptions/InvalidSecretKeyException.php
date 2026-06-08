<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when SPORA_SECRET_KEY is set but cannot be base64-decoded (strict mode).
 * Indicates a malformed env var; the key must be regenerated with random_bytes(32) and base64-encoded.
 */
final class InvalidSecretKeyException extends RuntimeException {}
