<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

/**
 * Per-message LLM token accounting + provider cache state.
 *
 * Field provenance (verify against vendor docs when bumping):
 *
 *   - `inputTokens`          OpenAI `usage.prompt_tokens` | Anthropic `usage.input_tokens`
 *   - `outputTokens`         OpenAI `usage.completion_tokens` | Anthropic `usage.output_tokens`
 *   - `reasoningTokens`      OpenAI `usage.completion_tokens_details.reasoning_tokens` (0 on Anthropic
 *                            — Anthropic emits reasoning as `thinking` blocks, not a counter)
 *   - `cachedTokens`         OpenAI `usage.prompt_tokens_details.cached_tokens` (0 on Anthropic)
 *   - `cacheCreationTokens`  Anthropic `usage.cache_creation_input_tokens` (0 on OpenAI)
 *   - `cacheReadTokens`      Anthropic `usage.cache_read_input_tokens` (0 on OpenAI)
 *   - `provider`             `'openai' | 'anthropic' | 'unknown'` (legacy rows where usage wasn't captured)
 *   - `rawUsage`             The complete `usage` subobject the provider returned, preserved verbatim for
 *                            forensics. NEVER serialised to the frontend.
 *   - `driverMetaInfo`       Catch-all for provider-specific metadata we don't want to commit to as a
 *                            typed column (e.g. Anthropic `service_tier`). Promote a field to a typed
 *                            column the moment you start aggregating it — typed columns are the contract;
 *                            `driverMetaInfo` is the soft backstop.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
        public int $cachedTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $cacheReadTokens = 0,
        public string $provider = 'unknown',
        public ?array $rawUsage = null,
        public ?array $driverMetaInfo = null,
    ) {}

    public function add(self $other): self
    {
        return new self(
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            reasoningTokens: $this->reasoningTokens + $other->reasoningTokens,
            cachedTokens: $this->cachedTokens + $other->cachedTokens,
            cacheCreationTokens: $this->cacheCreationTokens + $other->cacheCreationTokens,
            cacheReadTokens: $this->cacheReadTokens + $other->cacheReadTokens,
            provider: $this->provider !== 'unknown' ? $this->provider : $other->provider,
        );
    }

    /**
     * @return array<string, int|string|array|null>
     */
    public function toArray(): array
    {
        return [
            'input_tokens'          => $this->inputTokens,
            'output_tokens'         => $this->outputTokens,
            'reasoning_tokens'      => $this->reasoningTokens,
            'cached_tokens'         => $this->cachedTokens,
            'cache_creation_tokens' => $this->cacheCreationTokens,
            'cache_read_tokens'     => $this->cacheReadTokens,
            'provider'              => $this->provider,
            'raw_usage'             => $this->rawUsage,
            'driver_meta_info'      => $this->driverMetaInfo,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: (int) ($data['input_tokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? 0),
            reasoningTokens: (int) ($data['reasoning_tokens'] ?? 0),
            cachedTokens: (int) ($data['cached_tokens'] ?? 0),
            cacheCreationTokens: (int) ($data['cache_creation_tokens'] ?? 0),
            cacheReadTokens: (int) ($data['cache_read_tokens'] ?? 0),
            provider: (string) ($data['provider'] ?? 'unknown'),
            rawUsage: is_array($data['raw_usage'] ?? null) ? $data['raw_usage'] : null,
            driverMetaInfo: is_array($data['driver_meta_info'] ?? null) ? $data['driver_meta_info'] : null,
        );
    }

    /**
     * Build a Usage from a provider `usage` subobject tagged with its provider.
     *
     * @param array<string, mixed>|null $usage
     */
    public static function fromProviderUsage(?array $usage, string $provider): self
    {
        if ($usage === null || $usage === []) {
            return new self(provider: $provider);
        }

        if ($provider === 'anthropic') {
            $serviceTier = $usage['service_tier'] ?? null;

            return new self(
                inputTokens: (int) ($usage['input_tokens'] ?? 0),
                outputTokens: (int) ($usage['output_tokens'] ?? 0),
                cacheCreationTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
                cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
                provider: 'anthropic',
                rawUsage: $usage,
                driverMetaInfo: $serviceTier !== null ? ['service_tier' => $serviceTier] : null,
            );
        }

        // Default to OpenAI Chat Completions shape — `prompt_tokens_details.cached_tokens`
        // and `completion_tokens_details.reasoning_tokens`. Same fields apply to the
        // Responses API responses for the cache counter; reasoning moves to a different
        // subkey there (handled when we land the Responses-API switch).
        $promptDetails = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $completionDetails = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];

        return new self(
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            reasoningTokens: (int) ($completionDetails['reasoning_tokens'] ?? 0),
            cachedTokens: (int) ($promptDetails['cached_tokens'] ?? 0),
            provider: 'openai',
            rawUsage: $usage,
        );
    }
}
