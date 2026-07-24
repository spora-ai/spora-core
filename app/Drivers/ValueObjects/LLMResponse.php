<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

/**
 * Normalized result of one provider completion.
 *
 * Reasoning moved from a flat string to signed `contentBlocks`; filter by
 * {@see ContentBlock::TYPE_THINKING} when Anthropic chain continuity matters.
 */
final readonly class LLMResponse
{
    public Usage $usage;

    /**
     * @param ?string                  $content           Non-null display text (assistant's plain message).
     * @param list<ToolCall>           $toolCalls         Parallel tool calls requested in this turn.
     * @param int                      $inputTokens       Legacy counter; prefer `$usage->inputTokens`.
     * @param int                      $outputTokens      Legacy counter; prefer `$usage->outputTokens`.
     * @param string                   $completionId      Provider-side completion identifier.
     * @param list<ContentBlock>       $contentBlocks     Ordered provider content retained for replay.
     * @param Usage|null               $usage             Authoritative per-message usage. When supplied,
     *                                                  its counters supersede the legacy `$inputTokens` /
     *                                                  `$outputTokens` fields.
     * @param string|null              $displayReasoning  Human-readable reasoning text. Display-only —
     *                                                  not signed by the provider and never replayed
     *                                                  into a `thinking` block on the next turn.
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public string $completionId,
        public array $contentBlocks = [],
        ?Usage $usage = null,
        public ?string $displayReasoning = null,
    ) {
        $this->usage = $usage ?? new Usage();
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
