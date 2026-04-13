<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

final readonly class LLMResponse
{
    /**
     * @param  ?string       $content     Non-null when the LLM returns text (task complete or no tool needed).
     * @param  list<ToolCall> $toolCalls  Non-empty when the LLM requests one or more tool invocations.
     *                                   Modern LLMs fire parallel tool calls in a single response.
     */
    public function __construct(
        public ?string $content,
        public array   $toolCalls,
        public int     $inputTokens,
        public int     $outputTokens,

        /** Provider-issued completion ID for logging/debugging. */
        public string  $completionId,

        /** Provider-side reasoning / chain-of-thought (e.g. Anthropic extended thinking). */
        public ?string $reasoning = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
