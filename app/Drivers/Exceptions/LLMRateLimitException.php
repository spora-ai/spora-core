<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use RuntimeException;

/**
 * HTTP 429 rate-limit error. Caller should back off and retry.
 */
final class LLMRateLimitException extends RuntimeException {}
