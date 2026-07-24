<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * Anthropic-specific request behavior kept out of the driver constructor.
 */
final class AnthropicDriverOptions
{
    public function __construct(
        public readonly ?float $temperature = null,
        public readonly ?int $thinkingBudget = null,
        public readonly ?bool $supportsImageInput = null,

        /**
         * Enables driver-wide cache breakpoints on stable system and tool prefixes.
         */
        public readonly bool $enablePromptCaching = true,
    ) {}
}
