<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when expanding a prompt template would exceed the expansion ceiling.
 */
final class PromptExpansionTooLargeException extends RuntimeException {}
