<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * A stored setting exists but cannot be decrypted with the current secret key.
 *
 * Thrown by {@see \Spora\Core\Config::get()} rather than swallowed during the
 * overlay, so the consumer that needed the credential fails loudly. See
 * `SettingsRepository::decodeRow()` for the boot-time half of this.
 */
final class SettingsDecryptionException extends RuntimeException
{
}