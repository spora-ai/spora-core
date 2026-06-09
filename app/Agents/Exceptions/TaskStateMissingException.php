<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;

/**
 * Thrown when the Orchestrator cannot resolve a task or its AgentState while
 * resuming or rejecting a task awaiting approval.
 */
final class TaskStateMissingException extends RuntimeException {}
