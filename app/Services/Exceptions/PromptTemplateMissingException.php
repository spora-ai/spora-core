<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a scheduled run references a prompt template that no longer exists.
 */
final class PromptTemplateMissingException extends RuntimeException {}
