<?php

declare(strict_types=1);

namespace Spora\Metering;

use Spora\Drivers\ValueObjects\Usage;

/**
 * The tokens one provider request consumed.
 *
 * Wraps the core's own {@see Usage} rather than restating its counters, so
 * a meter sees the same numbers the platform records and there is no second
 * token accounting to keep in step.
 */
final readonly class LlmUsage
{
    public function __construct(
        public Usage $usage,
        public string $provider,
        public string $model,
    ) {}

    public function inputTokens(): int
    {
        return $this->usage->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->usage->outputTokens;
    }

    public function cachedTokens(): int
    {
        return $this->usage->cachedTokens;
    }

    /**
     * Every token the provider reported for this call.
     *
     * The two providers count their caches differently, and the sum has to
     * hold for both: OpenAI folds cached prompt tokens into
     * `prompt_tokens` and reports no separate cache counters, while
     * Anthropic reports `cache_read_input_tokens` and
     * `cache_creation_input_tokens` *beside* `input_tokens`. Adding the
     * Anthropic counters is therefore correct for OpenAI too — they are
     * zero there — while adding them to a total that already includes
     * `cachedTokens` would double-count the OpenAI case.
     */
    public function totalTokens(): int
    {
        return $this->usage->inputTokens
            + $this->usage->outputTokens
            + $this->usage->cacheReadTokens
            + $this->usage->cacheCreationTokens;
    }
}
