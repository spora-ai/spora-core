<?php

declare(strict_types=1);

namespace Spora\Plugins\Exceptions;

use RuntimeException;

/**
 * Thrown when a plugin manifest is missing, malformed, or fails
 * to load for any reason during PluginLoader::discover() / load().
 */
final class PluginLoadFailedException extends RuntimeException {}
