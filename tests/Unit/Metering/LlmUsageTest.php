<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Spora\Drivers\ValueObjects\Usage;
use Spora\Metering\LlmUsage;

/**
 * The token total a meter measures an owner against.
 *
 * The providers disagree about whether the cache counters sit inside the
 * input count or beside it, so a total that adds a counter the input count
 * already covers counts the same tokens twice — and a limit measured in
 * total tokens would be reached early.
 */
it('counts the Chat Completions tokens once', function (): void {
    // `prompt_tokens` covers the cached tokens, so the total is their sum.
    $usage = Usage::fromProviderUsage([
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'total_tokens' => 150,
        'prompt_tokens_details' => ['cached_tokens' => 80],
    ], 'openai');

    expect((new LlmUsage($usage, 'openai_compatible', 'gpt-4o'))->totalTokens())->toBe(150);
});

it('counts the Responses tokens once', function (): void {
    // The Responses API reports the tokens it wrote to the cache next to
    // the ones it read, and its `input_tokens` covers both — the provider's
    // own `total_tokens` is 314, not 354.
    $usage = Usage::fromProviderUsage([
        'input_tokens' => 291,
        'output_tokens' => 23,
        'total_tokens' => 314,
        'input_tokens_details' => ['cached_tokens' => 120, 'cache_write_tokens' => 40],
    ], 'openai_responses');

    expect((new LlmUsage($usage, 'openai_compatible', 'gpt-4.1'))->totalTokens())->toBe(314);
});

it('adds the Anthropic cache counters, which its input count excludes', function (): void {
    // Anthropic's `input_tokens` counts only the tokens that were neither
    // read from nor written to the cache.
    $usage = Usage::fromProviderUsage([
        'input_tokens' => 25,
        'cache_creation_input_tokens' => 1500,
        'cache_read_input_tokens' => 2000,
        'output_tokens' => 300,
    ], 'anthropic');

    expect((new LlmUsage($usage, 'anthropic', 'claude-sonnet-4-5'))->totalTokens())->toBe(3825);
});

it('counts an unmeasured call as nothing', function (): void {
    $usage = Usage::fromProviderUsage(null, 'openai');

    expect((new LlmUsage($usage, 'openai_compatible', 'gpt-4o'))->totalTokens())->toBe(0);
});
