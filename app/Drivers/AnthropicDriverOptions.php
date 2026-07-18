<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * Anthropic-specific driver options: sampling temperature + thinking budget
 * + image-input capability override.
 *
 * Bundled into a single value object so the AnthropicCompatibleDriver
 * constructor stays under the SonarQube S107 7-parameter cap.
 */
final class AnthropicDriverOptions
{
    public function __construct(
        public readonly ?float $temperature = null,
        public readonly ?int $thinkingBudget = null,
        public readonly ?bool $supportsImageInput = null,
    ) {}
}
