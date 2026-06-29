<?php

declare(strict_types=1);

namespace Spora\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when a Spora entry point runs without the BASE_PATH constant being
 * defined first. The constant is required so the container can locate the
 * consumer's project root and the framework's vendor install directory.
 */
final class BasePathNotDefinedException extends RuntimeException {}
