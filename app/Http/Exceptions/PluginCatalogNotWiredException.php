<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Http\PluginsController::catalog()} when the
 * `SPORA_PLUGIN_CATALOG_ENABLED` flag is on but the PluginCatalogService was
 * not registered in the DI container — i.e. an operator-facing wiring bug
 * the runtime can't recover from. Mapped to HTTP 500 by the Kernel so the
 * misconfiguration is loud during ops rather than silently hidden as a 404.
 */
final class PluginCatalogNotWiredException extends RuntimeException {}
