<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Services\PluginCatalogService} when the on-disk cache
 * file cannot be written (permissions, disk full, etc.). Surfaces as an HTTP
 * 500 via the Kernel — there is no useful client-side recovery, and the
 * catalog will simply be re-fetched from Packagist on the next request.
 */
final class PluginCatalogCacheWriteException extends RuntimeException {}
