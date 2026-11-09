<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Tools\ToolSettingSchema;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The invariant that keeps the two lists in step: the levels a driver
 * declares are exactly the options its `effort` setting offers, so the
 * picker cannot offer a level the driver would not send.
 *
 * The `''` option is "Model default" — nothing is sent — and is
 * therefore not a level.
 */
test('the effort levels a driver declares are the options its effort setting offers', function (string $driverClass): void {
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
        model: 'test-model',
        baseUrl: 'https://example.test',
        httpClient: new MockHttpClient(),
    );

    expect(array_keys($driver->effortLevels()))->toBe($optionKeys);
})->with([
    OpenAICompatibleDriver::class,
    AnthropicCompatibleDriver::class,
    OpenAIResponsesDriver::class,
]);
