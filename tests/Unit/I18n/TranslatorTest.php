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

/**
 * A tool's `describeAction()` interpolates its arguments itself, so what
 * reaches the lookup is the result, while the catalogue key is the template
 * it came from. These cover the matching that bridges the two.
 */
describe('template matching', function (): void {
    test('a rendered string is translated through the template it came from', function (): void {
        $translator = new Translator(new CatalogLoader([], makeLocaleDir([
            'de' => ["Create email folder '%s'" => "E-Mail-Ordner '%s' erstellen"],
        ], 'template-hit')));
        $translator->useLocale('de');

        expect($translator->trans("Create email folder 'Archive'"))->toBe("E-Mail-Ordner 'Archive' erstellen");
    });

    test('a template with several placeholders substitutes every one of them', function (): void {
        $translator = new Translator(new CatalogLoader([], makeLocaleDir([
            'de' => ["Mark todo '%s' as %s." => "Todo '%s' als %s markieren."],
        ], 'template-many')));
        $translator->useLocale('de');

        expect($translator->trans("Mark todo 'Buy milk' as done."))->toBe("Todo 'Buy milk' als done markieren.");
    });

    test('an exact catalogue entry still wins over a template', function (): void {
        $translator = new Translator(new CatalogLoader([], makeLocaleDir([
            'de' => ['Save %s' => 'Speichern %s', 'Save now' => 'Jetzt speichern'],
        ], 'template-exact')));
        $translator->useLocale('de');

        expect($translator->trans('Save now'))->toBe('Jetzt speichern');
    });

    test('a rendered string matching no template is left alone', function (): void {
        $translator = new Translator(new CatalogLoader([], makeLocaleDir([
            'de' => ["Create email folder '%s'" => "E-Mail-Ordner '%s' erstellen"],
        ], 'template-miss')));
        $translator->useLocale('de');

        expect($translator->trans('Something else entirely'))->toBe('Something else entirely');
    });

    test('two templates matching equally well are not used', function (): void {
        // A guess here would put the wrong sentence in front of an operator
        // approving a tool call, so neither is used.
        $translator = new Translator(new CatalogLoader([], makeLocaleDir([
            'de' => ['Item %s' => 'Element %s', 'Item %d' => 'Element %d'],
        ], 'template-ambiguous')));
        $translator->useLocale('de');

        expect($translator->trans('Item 7'))->toBe('Item 7');
    });

    test('the more specific template wins when both match', function (): void {
        $translator = new Translator(new CatalogLoader([], makeLocaleDir([
            'de' => ['Item %s' => 'Element %s', 'Item %s of the list' => 'Element %s der Liste'],
        ], 'template-specific')));
        $translator->useLocale('de');

        expect($translator->trans('Item 7 of the list'))->toBe('Element 7 der Liste');
    });

    test('a template with an unknown percent sign is not matched', function (): void {
        // A `%` that is not a known placeholder means the shape of the
        // string is unknown, and guessing at it could mistranslate.
        $translator = new Translator(new CatalogLoader([], makeLocaleDir([
            'de' => ['100% of %s' => '100 % von %s'],
        ], 'template-percent')));
        $translator->useLocale('de');

        expect($translator->trans('100% of the list'))->toBe('100% of the list');
    });
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
