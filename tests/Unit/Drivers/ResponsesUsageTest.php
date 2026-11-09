<?php

declare(strict_types=1);

use Spora\Drivers\ValueObjects\Usage;

/**
 * The Responses API names every counter differently from Chat
 * Completions, so a payload that falls through to the Chat Completions
 * branch records a silent zero for each of them. The counters are
 * therefore asserted non-zero rather than merely present.
 */
test('the Responses usage object populates every counter', function (): void {
    $usage = Usage::fromProviderUsage([
        'input_tokens' => 291,
        'output_tokens' => 23,
        'total_tokens' => 314,
        'input_tokens_details' => ['cached_tokens' => 120, 'cache_write_tokens' => 40],
        'output_tokens_details' => ['reasoning_tokens' => 17],
    ], 'openai_responses');

    expect($usage->inputTokens)->toBe(291)
        ->and($usage->outputTokens)->toBe(23)
        ->and($usage->cachedTokens)->toBe(120)
        ->and($usage->cacheCreationTokens)->toBe(40)
        ->and($usage->reasoningTokens)->toBe(17);
});

test('the Responses usage is stored under the openai provider tag', function (): void {
    $usage = Usage::fromProviderUsage([
        'input_tokens' => 10,
        'output_tokens' => 5,
    ], 'openai_responses');

    expect($usage->provider)->toBe('openai');
});

test('the raw usage is preserved for the on-disk forensics copy', function (): void {
    $raw = [
        'input_tokens' => 10,
        'output_tokens' => 5,
        'input_tokens_details' => ['cached_tokens' => 1, 'cache_write_tokens' => 2],
        'output_tokens_details' => ['reasoning_tokens' => 3],
    ];

    expect(Usage::fromProviderUsage($raw, 'openai_responses')->rawUsage)->toBe($raw);
});

test('a Responses payload read as a Chat Completions payload would record nothing', function (): void {
    $responses = Usage::fromProviderUsage([
        'input_tokens' => 291,
        'output_tokens' => 23,
        'input_tokens_details' => ['cached_tokens' => 120],
        'output_tokens_details' => ['reasoning_tokens' => 17],
    ], 'openai_responses');

    $chatCompletions = Usage::fromProviderUsage([
        'input_tokens' => 291,
        'output_tokens' => 23,
        'input_tokens_details' => ['cached_tokens' => 120],
        'output_tokens_details' => ['reasoning_tokens' => 17],
    ], 'openai');

    expect($chatCompletions->inputTokens)->toBe(0)
        ->and($chatCompletions->outputTokens)->toBe(0)
        ->and($chatCompletions->cachedTokens)->toBe(0)
        ->and($chatCompletions->reasoningTokens)->toBe(0)
        ->and($responses->inputTokens)->not->toBe(0);
});

test('an empty usage object keeps the unknown provider sentinel', function (): void {
    $usage = Usage::fromProviderUsage(null, 'openai_responses');

    expect($usage->provider)->toBe('unknown')
        ->and($usage->inputTokens)->toBe(0);
});
