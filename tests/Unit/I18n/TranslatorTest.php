<?php

declare(strict_types=1);

use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-i18n-*') ?: [] as $dir) {
        removeLocaleDir($dir);
    }
});

test('a message with no catalogue entry renders as its English source', function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir(['de' => ['Save' => 'Speichern']], 'fallback')));
    $translator->useLocale('de');

    expect($translator->trans('Not in the catalogue'))->toBe('Not in the catalogue');
});

test('a message with a catalogue entry renders in the active locale', function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir(['de' => ['Save' => 'Speichern']], 'hit')));
    $translator->useLocale('de');

    expect($translator->trans('Save'))->toBe('Speichern');
});

test('an unknown locale renders English rather than an empty string', function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir(['de' => ['Save' => 'Speichern']], 'unknown')));
    $translator->useLocale('xx');

    expect($translator->trans('Save'))->toBe('Save')
        ->and($translator->getLocale())->toBe('xx');
});

test('the locale starts at English and follows useLocale()', function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir([], 'default-locale')));

    expect($translator->getLocale())->toBe(Translator::FALLBACK_LOCALE);

    $translator->useLocale('  de  ');

    expect($translator->getLocale())->toBe('de');
});

test('trans() replaces {named} placeholders from the parameter list', function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir([
        'de' => ['Member {name} removed from {group}.' => '{name} wurde aus {group} entfernt.'],
    ], 'parameters')));
    $translator->useLocale('de');

    expect($translator->trans(
        'Member {name} removed from {group}.',
        ['name' => 'Ada', 'group' => 'Ops'],
    ))->toBe('Ada wurde aus Ops entfernt.');
});

test('a placeholder with no parameter is left as written', function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir([
        'de' => ['Hi {name}, you have {count}.' => 'Hallo {name}, du hast {count}.'],
    ], 'missing-parameter')));
    $translator->useLocale('de');

    // Dropping the placeholder would render "Hallo Ada, du hast ." and read
    // as a bug in the translation rather than as a missing parameter.
    expect($translator->trans('Hi {name}, you have {count}.', ['name' => 'Ada']))
        ->toBe('Hallo Ada, du hast {count}.');
});

test('an empty message stays empty and never reads the catalogue', function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir(['de' => ['' => 'Nichts']], 'empty')));

    expect($translator->trans(''))->toBe('');
});
