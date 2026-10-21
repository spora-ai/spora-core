<?php

declare(strict_types=1);

use Spora\I18n\CatalogLoader;
use Spora\I18n\LocaleResolver;

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-i18n-*') ?: [] as $dir) {
        removeLocaleDir($dir);
    }
});

/** A resolver whose catalogues are exactly the locales named in `$locales`. */
function resolverFor(array $locales, string $instanceDefault = 'en'): LocaleResolver
{
    $catalogues = [];
    foreach ($locales as $locale) {
        $catalogues[$locale] = [];
    }

    return new LocaleResolver(new CatalogLoader([], makeLocaleDir($catalogues)), $instanceDefault);
}

test("a user's own locale wins over the instance default", function (): void {
    $resolver = resolverFor(['en', 'de'], 'en');

    expect($resolver->resolve('de'))->toBe('de');
});

test('a user with no locale of their own gets the instance default', function (): void {
    $resolver = resolverFor(['en', 'de'], 'de');

    expect($resolver->resolve(null))->toBe('de');
});

test('an instance default nobody has translated falls through to English', function (): void {
    $resolver = resolverFor(['en'], 'de');

    expect($resolver->resolve(null))->toBe('en');
});

test('a user locale with no catalogue falls through rather than rendering a language nobody translated', function (): void {
    $resolver = resolverFor(['en', 'de'], 'de');

    expect($resolver->resolve('xx'))->toBe('de');
});

test('English is the last link even when the instance default is unset', function (): void {
    $resolver = resolverFor([], '  ');

    expect($resolver->resolve('  '))->toBe(LocaleResolver::FALLBACK_LOCALE);
});

test('the picker option list is every catalogue in core and the installed plugins', function (): void {
    $core = makeLocaleDir(['en' => [], 'de' => []], 'picker-core');
    $plugin = makeLocaleDir(['fr' => []], 'picker-plugin');

    $resolver = new LocaleResolver(new CatalogLoader([$plugin], $core));

    expect($resolver->availableLocales())->toBe(['de', 'en', 'fr']);
});
