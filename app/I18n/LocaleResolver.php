<?php

declare(strict_types=1);

namespace Spora\I18n;

/**
 * Picks the locale a request renders in.
 *
 * The chain is `users.locale` → `SPORA_DEFAULT_LANG` → `en`, and the first
 * link that has a catalogue wins — so a user whose stored locale names a
 * language nobody has translated falls through to the instance default
 * rather than to a screen of English.
 *
 * `Accept-Language` is deliberately absent. The instance owner sets one
 * default in the admin UI, and a browser header overriding it would make
 * the rendered language depend on the machine rather than the install, and
 * the same user see two languages on two machines.
 */
final readonly class LocaleResolver
{
    /** The locale every install renders in until something else is declared. */
    public const FALLBACK_LOCALE = 'en';

    /**
     * @param string $instanceDefault The `SPORA_DEFAULT_LANG` setting value.
     */
    public function __construct(
        private readonly CatalogLoader $catalogs,
        private readonly string $instanceDefault = self::FALLBACK_LOCALE,
    ) {}

    /**
     * @param ?string $userLocale The caller's `users.locale`. Null is "auto".
     */
    public function resolve(?string $userLocale): string
    {
        foreach ([$userLocale, $this->instanceDefault, self::FALLBACK_LOCALE] as $candidate) {
            $locale = $candidate === null ? '' : trim($candidate);
            if ($locale !== '' && $this->catalogs->has($locale)) {
                return $locale;
            }
        }

        return self::FALLBACK_LOCALE;
    }

    /**
     * Every locale with a catalogue in core or in an installed plugin — the
     * language picker's whole option list.
     *
     * @return list<string>
     */
    public function availableLocales(): array
    {
        return $this->catalogs->locales();
    }
}
