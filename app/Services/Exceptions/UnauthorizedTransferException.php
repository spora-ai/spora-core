<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when an agent transfer is blocked because the caller is not the
 * controller of either the source or target principal. Maps to HTTP 403 at
 * the controller boundary.
 */
final class UnauthorizedTransferException extends RuntimeException {}
