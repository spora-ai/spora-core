<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when a request names a reasoning effort the framework does not
 * know.
 *
 * Its own type because the caller answers a 422 on it: an unknown level is
 * a malformed body, not a failed task.
 */
final class EffortValidationException extends RuntimeException {}
