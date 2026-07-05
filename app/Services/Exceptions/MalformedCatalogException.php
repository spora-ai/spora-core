<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Services\PluginCatalogService} when Packagist
 * returns a body in an unexpected shape — strongly suggests an upstream
 * API change or a proxy stripping content. Mapped to HTTP 502 by the Kernel.
 */
final class MalformedCatalogException extends RuntimeException {}
