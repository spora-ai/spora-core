<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * Anthropic-specific request behavior kept out of the driver constructor.
 *
 * The driver constructor sits at the seven-parameter cap, so the
 * settings that do not fit ride in this object.
 */
final class AnthropicDriverOptions
{
    public function __construct(
        public readonly ?bool $supportsImageInput = null,

        /**
         * Enables driver-wide cache breakpoints on stable system and tool prefixes.
         */
        public readonly bool $enablePromptCaching = true,
    ) {}
}
