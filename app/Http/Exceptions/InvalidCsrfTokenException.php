<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when CSRF token validation fails.
 * Caught by the Kernel and converted to a 403 JSON response.
 */
final class InvalidCsrfTokenException extends RuntimeException {}
