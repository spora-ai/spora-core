<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

/**
 * Normalized result of one provider completion.
 *
 * Reasoning is reachable via `contentBlocks[]` entries of
 * `type === ContentBlock::TYPE_THINKING`. The Anthropic driver carries
 * the provider's `signature` byte-identical for replay on the next turn;
 * the OpenAI compatible driver emits unsigned `thinking` blocks sourced
 * from `message.reasoning_content` / `message.reasoning` / inline
 * reasoning tags, which the Anthropic outbound path drops so a
 * mid-task driver switch cannot break Anthropic chain continuity.
 */
final readonly class LLMResponse
{
    public Usage $usage;

    /**
     * @param ?string             $content        Non-null display text (assistant's plain message).
     * @param list<ToolCall>      $toolCalls      Parallel tool calls requested in this turn.
     * @param int                 $inputTokens    Legacy counter; prefer `$usage->inputTokens`.
     * @param int                 $outputTokens   Legacy counter; prefer `$usage->outputTokens`.
     * @param string              $completionId   Provider-side completion identifier.
     * @param list<ContentBlock>  $contentBlocks  Ordered provider content retained for replay.
     * @param Usage|null          $usage          Authoritative per-message usage. When supplied,
     *                                           its counters supersede the legacy `$inputTokens` /
     *                                           `$outputTokens` fields.
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public string $completionId,
        public array $contentBlocks = [],
        ?Usage $usage = null,
    ) {
        $this->usage = $usage ?? new Usage();
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
