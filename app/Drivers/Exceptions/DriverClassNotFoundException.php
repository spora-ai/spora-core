<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use RuntimeException;

/**
 * Thrown when DriverFactory can't resolve a registered driver class.
 */
final class DriverClassNotFoundException extends RuntimeException {}
