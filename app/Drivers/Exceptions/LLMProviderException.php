<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use RuntimeException;

/**
 * Non-recoverable LLM API error.
 */
final class LLMProviderException extends RuntimeException {}
