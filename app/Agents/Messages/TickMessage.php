<?php

declare(strict_types=1);

namespace Spora\Agents\Messages;

/**
 * Dispatched into the Messenger bus to drive one iteration of the Orchestrator loop.
 * Synchronous transport executes the handler in-process immediately.
 */
final readonly class TickMessage
{
    public function __construct(
        public int $taskId,
    ) {}
}
