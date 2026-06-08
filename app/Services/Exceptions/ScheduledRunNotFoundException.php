<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a service cannot find the requested scheduled run.
 */
final class ScheduledRunNotFoundException extends RuntimeException {}
