<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Tools\ToolSettingSchema;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The invariant that keeps the two lists in step: the levels a driver
 * declares are among the options its `effort` setting offers, so the
 * picker cannot offer a level the driver would not send.
 *
 * The relation is a subset, not an equality: a driver's level set is a
 * property of the model it talks to, and a model takes fewer levels than
 * the setting offers — Sonnet 4.5 takes none of them.
 *
 * The `''` option is "Model default" — nothing is sent — and is
 * therefore not a level.
 */
test('the effort levels a driver declares are among the options its effort setting offers', function (string $driverClass, string $model): void {
    $effortSetting = null;
    foreach (ToolSettingSchema::collect($driverClass) as $setting) {
        if ($setting->key === 'effort') {
            $effortSetting = $setting;
        }
    }

    expect($effortSetting)->not->toBeNull();

    $optionKeys = array_values(array_filter(
        array_keys($effortSetting->options),
        static fn(string $key): bool => $key !== '',
    ));

    $driver = new $driverClass(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://example.test',
        httpClient: new MockHttpClient(),
    );

    $declared = array_keys($driver->effortLevels());

    expect($declared)->toBeArray()
        ->and(array_diff($declared, $optionKeys))->toBe([]);
})->with([
    [OpenAICompatibleDriver::class, 'gpt-4o'],
    [OpenAICompatibleDriver::class, 'gpt-5.5'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-5-5'],
    [AnthropicCompatibleDriver::class, 'claude-opus-4-6'],
    [AnthropicCompatibleDriver::class, 'claude-opus-4-5-20251101'],
    [AnthropicCompatibleDriver::class, 'claude-sonnet-4-5-20250929'],
    [OpenAIResponsesDriver::class, 'gpt-6-astra'],
]);
