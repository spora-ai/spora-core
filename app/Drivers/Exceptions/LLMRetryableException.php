<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use RuntimeException;

/**
 * Marker interface for LLM errors that are safe to retry with backoff.
 * Covers HTTP 429, 502, 503, 504, 520, 529.
 */
final class LLMRetryableException extends RuntimeException {}
