<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown by AuthGuard::requireAuth() when no authenticated session is present.
 * Caught by the Kernel and converted to a 401 JSON response.
 */
final class UnauthenticatedException extends RuntimeException {}
