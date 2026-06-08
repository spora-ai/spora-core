<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller attempts an Orchestrator state transition (e.g. continue)
 * from a task status that does not permit it.
 */
final class InvalidTaskTransitionException extends RuntimeException {}
