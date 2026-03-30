<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

final readonly class LLMResponse
{
    /**
     * Exactly one of $content or $toolCall is non-null per response.
     */
    public function __construct(
        /** Non-null when LLM returns text (task complete or no tool needed). */
        public ?string   $content,

        /** Non-null when LLM requests a tool invocation. */
        public ?ToolCall $toolCall,
        public int    $inputTokens,
        public int    $outputTokens,

        /** Provider-issued completion ID for logging/debugging. */
        public string $completionId,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCall !== null;
    }
}
