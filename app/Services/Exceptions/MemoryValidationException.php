<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when memory input validation fails (e.g. name missing on creation).
 */
final class MemoryValidationException extends RuntimeException {}
