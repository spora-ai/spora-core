<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Services\PluginCatalogService} when the upstream
 * Packagist endpoint returns a syntactically invalid (or unexpectedly
 * shaped) JSON payload. The HTTP status was OK, so the connection worked,
 * but the body is not the shape we expect — strongly suggests a Packagist
 * API change or a proxy stripping content.
 *
 * Mapped to HTTP 502 by the Kernel.
 */
final class MalformedCatalogException extends RuntimeException {}
