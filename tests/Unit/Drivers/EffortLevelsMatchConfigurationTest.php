<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\EffortAwareDriverInterface;
use Spora\Drivers\EffortLevel;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Services\LLMConfigService;

/**
 * The levels a driver declares and the levels a configuration offers.
 *
 * The relation this file used to pin — the declared levels are a subset
 * of the `effort` setting's static option list — is gone: the setting is
 * free text, because a static list cannot describe a per-row level set.
 *
 * What still holds is the relation it protected. The picker offers what
 * the configuration offers, and the driver sends what the configuration
 * offers, so the two cannot drift apart — and a canonical name keeps its
 * pretty label wherever it is shown.
 */
function effortLevelsMatchFactory(): DriverFactory
{
    $llmConfigService = new LLMConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        [OpenAICompatibleDriver::class, AnthropicCompatibleDriver::class, OpenAIResponsesDriver::class],
    );

    return new DriverFactory(new NullLogger(), $llmConfigService, 60);
}

/**
 * The driver a configuration's settings describe.
 *
 * @param array<string, mixed> $settings
 */
function effortLevelsMatchDriver(string $driverClass, array $settings): EffortAwareDriverInterface
{
    $driver = effortLevelsMatchFactory()->makeDriverFromSettings($driverClass, $settings + [
        'api_key' => 'test',
        'base_url' => 'https://example.test',
    ]);

    if (!$driver instanceof EffortAwareDriverInterface) {
        throw new RuntimeException($driverClass . ' declares no reasoning-effort levels.');
    }

    return $driver;
}

test('the levels a configuration names are the levels the driver declares', function (string $driverClass, string $model): void {
    $driver = effortLevelsMatchDriver($driverClass, [
        'model' => $model,
        'effort_levels' => 'low, high',
    ]);

    expect(array_keys($driver->effortLevels()))->toBe(['low', 'high']);
})->with([
    [OpenAICompatibleDriver::class, 'gpt-4o'],
    [OpenAICompatibleDriver::class, 'gpt-5.5'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-5-5'],
    [AnthropicCompatibleDriver::class, 'claude-opus-4-6'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-4-5-20250929'],
    [OpenAIResponsesDriver::class, 'gpt-6-astra'],
]);

test('a configuration that names no levels leaves the driver\'s own set in place', function (string $driverClass, string $model): void {
    $driver = effortLevelsMatchDriver($driverClass, ['model' => $model]);

    $declared = array_keys($driver->effortLevels());
    $offered = effortLevelsMatchDriver($driverClass, ['model' => $model, 'effort_levels' => implode(',', $declared)]);

    expect(array_keys($offered->effortLevels()))->toBe($declared);
})->with([
    [OpenAICompatibleDriver::class, 'gpt-4o'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-5-5'],
    [AnthropicCompatibleDriver::class, 'claude-opus-4-6'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-4-5-20250929'],
    [OpenAIResponsesDriver::class, 'gpt-6-astra'],
]);

test('the driver\'s own set is a subset of the canonical vocabulary', function (string $driverClass, string $model): void {
    $driver = effortLevelsMatchDriver($driverClass, ['model' => $model]);

    $canonical = array_column(EffortLevel::cases(), 'value');

    expect(array_diff(array_keys($driver->effortLevels()), $canonical))->toBe([]);
})->with([
    [OpenAICompatibleDriver::class, 'gpt-4o'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-5-5'],
    [AnthropicCompatibleDriver::class, 'claude-opus-4-6'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-4-5-20250929'],
    [OpenAIResponsesDriver::class, 'gpt-6-astra'],
]);

test('a canonical name the driver declares keeps its pretty label', function (string $driverClass, string $model): void {
    $driver = effortLevelsMatchDriver($driverClass, ['model' => $model]);

    $pretty = [
        'none' => 'None',
        'minimal' => 'Minimal',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'xhigh' => 'xHigh',
        'max' => 'Max',
    ];

    $declared = array_keys($driver->effortLevels());
    $expected = array_map(static fn(string $level): string => $pretty[$level], $declared);

    expect(array_map(EffortLevel::labelFor(...), $declared))->toBe($expected);
})->with([
    [OpenAICompatibleDriver::class, 'gpt-4o'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-5-5'],
    [AnthropicCompatibleDriver::class, 'claude-opus-4-6'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-4-5-20250929'],
    [OpenAIResponsesDriver::class, 'gpt-6-astra'],
]);
