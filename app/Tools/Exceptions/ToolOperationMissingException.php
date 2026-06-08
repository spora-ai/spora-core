<?php

declare(strict_types=1);

namespace Spora\Tools\Exceptions;

use RuntimeException;

/**
 * Thrown when a tool using the HasOperations trait is asked to resolve an
 * operation name but has no #[ToolOperation] attributes declared.
 */
final class ToolOperationMissingException extends RuntimeException {}
