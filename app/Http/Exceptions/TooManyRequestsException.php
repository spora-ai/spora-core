<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown by the `/tick` and `/worker/housekeeping` controllers when the
 * per-user DB rate limit (see {@see \Spora\Services\DbRateLimiter}) rejects
 * the call. Mapped to HTTP 429 by the Kernel.
 *
 * Distinct from the auth-flavoured `\Delight\Auth\TooManyRequestsException`
 * so the rate-limit policy can change without coupling the runtime to the
 * delight-im package.
 */
final class TooManyRequestsException extends RuntimeException {}
