<?php

declare(strict_types=1);

namespace Spora\Metering;

use Spora\Drivers\ValueObjects\Usage;

/**
 * The tokens one provider request consumed.
 *
 * Wraps the core's own {@see Usage} rather than copying its counters, so
 * a meter sees the numbers the platform records and there is no second
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
     * The providers disagree about whether the cache counters sit inside
     * the input count or beside it, and the sum has to hold for each:
     * Anthropic's `input_tokens` excludes the tokens it read from and wrote
     * to the cache, so the total has to add them back. OpenAI's input count
     * includes them — the Chat Completions `prompt_tokens` and the Responses
     * `input_tokens` both cover the cached and the cache-written tokens —
     * so adding the counters there would count them twice.
     */
    public function totalTokens(): int
    {
        $tokens = $this->usage->inputTokens + $this->usage->outputTokens;

        if ($this->usage->provider === 'anthropic') {
            $tokens += $this->usage->cacheReadTokens + $this->usage->cacheCreationTokens;
        }

        return $tokens;
    }
}
