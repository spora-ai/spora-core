<?php

declare(strict_types=1);

use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-i18n-*') ?: [] as $dir) {
        removeLocaleDir($dir);
    }
});

test('a plugin key that core also declares resolves to the core wording', function (): void {
    $core = makeLocaleDir(['de' => ['API key' => 'API-Schlüssel (Core)']], 'collision-core');
    $plugin = makeLocaleDir(['de' => ['API key' => 'Schlüssel vom Plugin']], 'collision-plugin');

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $loader = new CatalogLoader([$plugin], $core, $logger);

    // Core wins and the install keeps working: a third-party plugin must
    // not be able to reword a string the host owns.
    expect($loader->messagesFor('de'))->toBe(['API key' => 'API-Schlüssel (Core)']);
});

test('a key only a plugin declares resolves from the plugin catalogue', function (): void {
    $core = makeLocaleDir(['de' => []], 'merge-core');
    $plugin = makeLocaleDir(['de' => ['Only mine' => 'Nur von mir']], 'merge-plugin');

    $loader = new CatalogLoader([$plugin], $core);

    expect($loader->messagesFor('de'))->toBe(['Only mine' => 'Nur von mir']);
});

test('the first plugin to declare a key wins over the ones after it', function (): void {
    $core = makeLocaleDir(['de' => []], 'order-core');
    $first = makeLocaleDir(['de' => ['Shared' => 'Erster']], 'order-first');
    $second = makeLocaleDir(['de' => ['Shared' => 'Zweiter']], 'order-second');

    $loader = new CatalogLoader([$first, $second], $core);

    expect($loader->messagesFor('de'))->toBe(['Shared' => 'Erster']);
});

test('a missing catalogue file is not an error', function (): void {
    $core = makeLocaleDir(['de' => ['Save' => 'Speichern']], 'missing-file');

    $loader = new CatalogLoader([], $core);

    expect($loader->has('de'))->toBeTrue()
        ->and($loader->has('fr'))->toBeFalse()
        ->and($loader->messagesFor('fr'))->toBe([]);
});

test('a malformed catalogue file renders English instead of failing the request', function (): void {
    $core = makeLocaleDir([], 'malformed');
    file_put_contents($core . '/de.json', '{not json');

    $loader = new CatalogLoader([], $core);

    expect($loader->messagesFor('de'))->toBe([]);
});

test('a catalogue holding non-string values drops them rather than rendering "Array"', function (): void {
    $core = makeLocaleDir([], 'non-string');
    file_put_contents(
        $core . '/de.json',
        (string) json_encode(['Broken' => ['nested'], 'Fine' => 'Gut']),
    );

    $loader = new CatalogLoader([], $core);

    expect($loader->messagesFor('de'))->toBe(['Fine' => 'Gut']);
});

test('locales() lists every catalogue across core and plugins, sorted', function (): void {
    $core = makeLocaleDir(['en' => [], 'de' => []], 'locales-core');
    $plugin = makeLocaleDir(['fr' => []], 'locales-plugin');

    $loader = new CatalogLoader([$plugin], $core);

    expect($loader->locales())->toBe(['de', 'en', 'fr']);
});

test('the shipped catalogues are readable and the German one is complete', function (): void {
    $loader = new CatalogLoader();

    // The framework's own `locales/`, reached through Paths — the case no
    // unit test with a fixture tree covers.
    expect($loader->locales())->toContain('en', 'de')
        ->and($loader->messagesFor('en'))->not->toBeEmpty();

    $english = $loader->messagesFor('en');
    $german = $loader->messagesFor('de');

    // en.json is an identity map: a miss renders as the English source, so
    // this is what makes the fallback free.
    foreach ($english as $key => $value) {
        expect($value)->toBe($key);
    }

    $germanKeys = array_keys($german);
    $englishKeys = array_keys($english);
    sort($germanKeys);
    sort($englishKeys);

    expect($germanKeys)->toEqual($englishKeys);
});

test('the merge result reaches the Translator through the plugin catalogue', function (): void {
    $core = makeLocaleDir(['de' => ['Save' => 'Speichern']], 'wired-core');
    $plugin = makeLocaleDir(['de' => ['Send' => 'Senden']], 'wired-plugin');

    $translator = new Translator(new CatalogLoader([$plugin], $core));
    $translator->useLocale('de');

    expect($translator->trans('Save'))->toBe('Speichern')
        ->and($translator->trans('Send'))->toBe('Senden');
});
