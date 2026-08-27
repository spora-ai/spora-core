<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when an LLMDriverConfiguration write (create/update) targets a
 * principal the caller cannot access. Maps to 422 at the controller boundary.
 */
final class PrincipalNotAccessibleException extends RuntimeException {}
