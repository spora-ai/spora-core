<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;

/**
 * Thrown when the Orchestrator cannot resolve a tool name via the tool registry.
 */
final class ToolNotRegisteredException extends RuntimeException {}
