<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;

/**
 * Thrown when the Orchestrator cannot resolve an LLM driver configuration
 * for an agent (no preferred config and no usable global default).
 */
final class LlmConfigurationMissingException extends RuntimeException {}
