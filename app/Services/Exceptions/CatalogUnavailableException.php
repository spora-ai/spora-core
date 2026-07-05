<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Services\PluginCatalogService} when Packagist is
 * unreachable and no stale cache is available. Mapped to HTTP 503 by the
 * Kernel so the frontend can distinguish an upstream outage from a real bug.
 */
final class CatalogUnavailableException extends RuntimeException {}
