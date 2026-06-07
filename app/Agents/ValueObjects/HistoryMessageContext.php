<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

/**
 * Optional context attached to a TaskHistory row written by Orchestrator::appendHistory().
 * Groups the LLM token accounting and tool-call correlation fields so the helper itself
 * stays under the S107 parameter-count limit.
 */
final readonly class HistoryMessageContext
{
    public function __construct(
        public ?string $toolCallId      = null,
        public ?string $toolName        = null,
        public ?string $toolCallPayload = null,
        public int     $inputTokens     = 0,
        public int     $outputTokens    = 0,
        public ?string $reasoning       = null,
    ) {}
}
