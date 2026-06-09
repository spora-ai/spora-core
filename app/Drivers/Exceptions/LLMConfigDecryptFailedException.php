<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use RuntimeException;

/**
 * Thrown when LLM driver settings decryption/decoding fails.
 */
final class LLMConfigDecryptFailedException extends RuntimeException {}
