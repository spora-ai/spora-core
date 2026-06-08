<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;

/**
 * Thrown when a tool class does not satisfy an interface the Orchestrator
 * relies on (e.g. the HasOperations trait when resolving per-operation state).
 */
final class ToolContractException extends RuntimeException {}
