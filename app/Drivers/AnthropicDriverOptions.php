<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * Anthropic-specific request behavior kept out of the driver constructor.
 *
 * Temperature is no longer a constructor argument — it flows through the
 * shared {@see ValueObjects\LLMRequest::temperature} field
 * like the OpenAI driver does, so Anthropic and OpenAI share a single
 * source of truth via {@see \Spora\Agents\LlmConfigResolver}.
 */
final class AnthropicDriverOptions
{
    public function __construct(
        public readonly ?int $thinkingBudget = null,
        public readonly ?bool $supportsImageInput = null,

        /**
         * Enables driver-wide cache breakpoints on stable system and tool prefixes.
         */
        public readonly bool $enablePromptCaching = true,
    ) {}
}
