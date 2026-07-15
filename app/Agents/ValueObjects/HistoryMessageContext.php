<?php

declare(strict_types=1);

namespace Spora\Agents\ValueObjects;

/**
 * Optional context attached to a TaskHistory row written by Orchestrator::appendHistory().
 * Groups the LLM token accounting and tool-call correlation fields so the helper
 * doesn't have to take them as separate parameters.
 */
final readonly class HistoryMessageContext
{
    /**
     * @param array<int, array{media_id: string, kind: string}>|null $attachments
     *        Carried on `role=attachment` rows; expanded by
     *        {@see \Spora\Agents\MessageHistoryBuilder} into LLM content blocks.
     */
    public function __construct(
        public ?string $toolCallId      = null,
        public ?string $toolName        = null,
        public ?string $toolCallPayload = null,
        public int     $inputTokens     = 0,
        public int     $outputTokens    = 0,
        public ?string $reasoning       = null,
        public ?array  $attachments     = null,
    ) {}
}
