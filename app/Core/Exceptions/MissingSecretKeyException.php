<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when no secret key source is configured: neither SPORA_SECRET_KEY,
 * SPORA_KEY_PATH, nor config['key_path'] is set. The install flow must run first.
 */
final class MissingSecretKeyException extends RuntimeException {}
