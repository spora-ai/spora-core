<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown by AdminGuard::requireAdmin() when the authenticated user is not an admin.
 * Caught by the Kernel and converted to a 403 JSON response.
 */
final class ForbiddenException extends RuntimeException {}
