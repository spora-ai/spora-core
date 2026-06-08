<?php

declare(strict_types=1);

namespace Spora\Tools\Exceptions;

use RuntimeException;

/**
 * Thrown when a tool's parameter schema cannot be built — for example, when
 * an author-declared #[ToolParameter] collides with the synthesized operation
 * discriminator.
 */
final class ToolParameterSchemaException extends RuntimeException {}
