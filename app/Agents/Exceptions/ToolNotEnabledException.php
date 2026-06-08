<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;

/**
 * Thrown when the LLM attempts to call a tool that is not enabled for the
 * current agent. The orchestrator's handleToolCalls() catches this and
 * turns it into a System Error history row.
 */
final class ToolNotEnabledException extends RuntimeException {}
