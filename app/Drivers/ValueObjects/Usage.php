<?php

declare(strict_types=1);

namespace Spora\Drivers\ValueObjects;

/**
 * Per-message LLM token accounting + provider cache state.
 *
 * Field provenance (verify against vendor docs when bumping):
 *
 *   - `inputTokens`          OpenAI `usage.prompt_tokens` | Anthropic `usage.input_tokens`
 *                            | Responses `usage.input_tokens`
 *   - `outputTokens`         OpenAI `usage.completion_tokens` | Anthropic `usage.output_tokens`
 *                            | Responses `usage.output_tokens`
 *   - `reasoningTokens`      OpenAI `usage.completion_tokens_details.reasoning_tokens` |
 *                            Responses `usage.output_tokens_details.reasoning_tokens` (0 on
 *                            Anthropic — Anthropic emits reasoning as `thinking` blocks,
 *                            not a counter)
 *   - `cachedTokens`         OpenAI `usage.prompt_tokens_details.cached_tokens` |
 *                            Responses `usage.input_tokens_details.cached_tokens` (0 on Anthropic)
 *   - `cacheCreationTokens`  Anthropic `usage.cache_creation_input_tokens` |
 *                            Responses `usage.input_tokens_details.cache_write_tokens` (0 on
 *                            Chat Completions)
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

    /**
     * Return a new Usage with all six typed counters summed. The `provider`
     * field is merged with a concrete-provider-wins rule: `$this->provider`
     * keeps its tag unless it is the `unknown` sentinel, in which case the
     * caller's tag is adopted. This guarantees that a merged Usage never
     * claims a concrete provider it didn't actually see.
     */
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
     * Build a Usage from a provider `usage` subobject tagged with the
     * protocol it came from.
     *
     * The protocol picks the counter names; the stored `provider` tag
     * says which provider the numbers came from. The two are different
     * questions: both OpenAI protocols store `openai`, but they count
     * tokens under different names, and a Responses payload that falls
     * through to the Chat Completions branch records a silent zero for
     * every counter.
     *
     * When `$usage` is null or empty the returned Usage carries the
     * `unknown` provider sentinel so it can still be merged via
     * {@see self::add()} without polluting the caller's provider tag.
     *
     * @param array<string, mixed>|null $usage
     */
    public static function fromProviderUsage(?array $usage, string $protocol): self
    {
        if ($usage === null || $usage === []) {
            return new self(provider: 'unknown');
        }

        return match ($protocol) {
            'anthropic' => self::fromAnthropicUsage($usage),
            'openai_responses' => self::fromResponsesUsage($usage),
            default => self::fromChatCompletionsUsage($usage),
        };
    }

    /**
     * @param array<string, mixed> $usage
     */
    private static function fromAnthropicUsage(array $usage): self
    {
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

    /**
     * The Responses API names every counter differently: `input_tokens`
     * and `output_tokens` instead of `prompt_tokens` and
     * `completion_tokens`, with the cache and reasoning counters under
     * their own `*_tokens_details` objects.
     *
     * @param array<string, mixed> $usage
     */
    private static function fromResponsesUsage(array $usage): self
    {
        $inputDetails = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $outputDetails = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];

        return new self(
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            reasoningTokens: (int) ($outputDetails['reasoning_tokens'] ?? 0),
            cachedTokens: (int) ($inputDetails['cached_tokens'] ?? 0),
            // The Responses API reports the tokens it wrote to the cache
            // next to the ones it read; that is what Anthropic calls a
            // cache creation, so it lands on the same counter.
            cacheCreationTokens: (int) ($inputDetails['cache_write_tokens'] ?? 0),
            // The frontend's provider vocabulary, not the driver's
            // pricing tag: `getProviderName()` answers which price rows
            // a call is metered against, and that is `openai_compatible`
            // for both OpenAI protocols. This one says which provider
            // the counters came from, and the frontend renders it.
            provider: 'openai',
            rawUsage: $usage,
        );
    }

    /**
     * @param array<string, mixed> $usage
     */
    private static function fromChatCompletionsUsage(array $usage): self
    {
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
