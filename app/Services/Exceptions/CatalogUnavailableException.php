<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Services\PluginCatalogService} when the upstream
 * Packagist endpoint is unreachable, returns an HTTP 429 (rate-limited),
 * or another transport-level failure occurs AND no stale cache is available.
 *
 * Mapped to HTTP 503 by the Kernel so the frontend can distinguish a
 * transient upstream outage from a real bug.
 */
final class CatalogUnavailableException extends RuntimeException {}
