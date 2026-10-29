<?php

declare(strict_types=1);

use Spora\Plugins\PluginLoader;

const FIXTURE_SPEECH_PROVIDER = BASE_PATH . '/tests/Fixtures/plugins_with_speech_provider';

test('the speech-to-text provider classes are empty when no plugin contributes one', function (): void {
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_manifest']);
    $loader->boot();

    expect($loader->providerClasses()->speechToText())->toBe([]);
});

test('the speech-to-text provider classes carry the FQCN a fixture plugin contributes', function (): void {
    $loader = new PluginLoader([FIXTURE_SPEECH_PROVIDER]);
    $loader->boot();

    $classes = $loader->providerClasses()->speechToText();

    expect($classes)->toHaveCount(1);
    // We avoid referencing FixtureProvider::class directly because PHPStan
    // excludes tests/Fixtures from analysis; the class still loads at
    // runtime via the autoload-dev psr-4 entry in composer.json.
    expect($classes[0])->toEndWith('\\FixtureProvider');
    expect(class_exists($classes[0]))->toBeTrue();
});
