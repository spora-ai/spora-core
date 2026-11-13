<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\AnthropicDriverOptions;
use Spora\Drivers\OpenAICompatibleDriver;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Which levels a driver offers.
 *
 * The operator's list wins outright: they know their endpoint, and Spora
 * has no business second-guessing them. An unset list keeps the driver's
 * own set for the model, so a row that predates the setting behaves
 * exactly as it did before.
 *
 * The `supports_effort` toggle is the operator's way of saying the
 * endpoint has no effort parameter at all. A row that never stored the
 * key keeps working, so the toggle's absence is not a refusal.
 *
 * @param list<string> $levels
 */
function effortPrecedenceOpenAi(array $levels = [], ?bool $supportsEffort = null): OpenAICompatibleDriver
{
    return new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o',
        baseUrl: 'https://example.test',
        httpClient: new MockHttpClient(),
        effortLevels: $levels,
        supportsEffort: $supportsEffort,
    );
}

/**
 * @param list<string> $levels
 */
function effortPrecedenceAnthropic(string $model, array $levels = [], ?bool $supportsEffort = null): AnthropicCompatibleDriver
{
    return new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://example.test',
        httpClient: new MockHttpClient(),
        options: new AnthropicDriverOptions(
            configuredEffortLevels: $levels,
            supportsEffort: $supportsEffort,
        ),
    );
}

test('the operator\'s list wins outright over the model\'s own set', function (): void {
    $driver = effortPrecedenceOpenAi(['low', 'default']);

    expect(array_keys($driver->effortLevels()))->toBe(['low', 'default']);
});

test('an empty configured list falls through to the model-derived set', function (): void {
    $driver = effortPrecedenceAnthropic('claude-opus-4-6');

    expect(array_keys($driver->effortLevels()))->toBe(['low', 'medium', 'high', 'max']);
});

test('the effort toggle off yields no levels at all', function (): void {
    $driver = effortPrecedenceOpenAi(['low', 'high'], false);

    expect($driver->effortLevels())->toBe([]);
});

test('the effort toggle off beats the model-derived set too', function (): void {
    $driver = effortPrecedenceAnthropic('claude-opus-4-6', [], false);

    expect($driver->effortLevels())->toBe([]);
});

test('the effort toggle absent behaves as enabled', function (): void {
    $driver = effortPrecedenceOpenAi();

    expect(array_keys($driver->effortLevels()))
        ->toBe(['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max']);
});

test('a model the driver does not know keeps the whole Anthropic set', function (): void {
    // An unknown id names an endpoint with a model zoo of its own, and
    // the operator is the only one who knows what it takes. Withholding
    // the levels would strip the picker from every compatible proxy until
    // each operator configured a list.
    $driver = effortPrecedenceAnthropic('MiniMax-M3');

    expect(array_keys($driver->effortLevels()))
        ->toBe(['low', 'medium', 'high', 'xhigh', 'max']);
});

test('a configured name the canonical vocabulary does not know is offered verbatim', function (): void {
    $driver = effortPrecedenceOpenAi(['default', 'ultra']);

    expect($driver->effortLevels())->toBe(['default' => 'default', 'ultra' => 'ultra']);
});

test('the operator\'s list is offered in the order it was written', function (): void {
    $driver = effortPrecedenceOpenAi(['xhigh', 'none', 'max']);

    expect(array_keys($driver->effortLevels()))->toBe(['xhigh', 'none', 'max']);
});
