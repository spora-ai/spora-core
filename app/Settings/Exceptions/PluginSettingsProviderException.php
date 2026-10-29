<?php

declare(strict_types=1);

namespace Spora\Settings\Exceptions;

use RuntimeException;

/**
 * Thrown when a class the plugin loader named as a settings provider does
 * not implement {@see \Spora\Settings\PluginSettingsProviderInterface}.
 */
final class PluginSettingsProviderException extends RuntimeException {}
