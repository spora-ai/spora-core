<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * Thrown by SecurityManager::loadFromFile() when the secret key file is missing,
 * unreadable, or contains the wrong number of bytes. Distinct from
 * DecryptionFailedException (which signals a MAC mismatch on ciphertext).
 */
final class DecryptKeyMissingException extends RuntimeException {}
