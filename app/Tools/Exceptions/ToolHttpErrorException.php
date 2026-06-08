<?php

declare(strict_types=1);

namespace Spora\Tools\Exceptions;

use RuntimeException;

/**
 * Thrown when a tool's underlying HTTP request returns an error response.
 */
final class ToolHttpErrorException extends RuntimeException {}
