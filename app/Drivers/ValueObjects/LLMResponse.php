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
     * @param ?string $content Non-null when the LLM returns display text.
     * @param list<ToolCall> $toolCalls Parallel tool calls requested in this turn.
     * @param list<ContentBlock> $contentBlocks Ordered provider content retained for replay.
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
