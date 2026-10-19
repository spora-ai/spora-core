<?php

declare(strict_types=1);

namespace Spora\I18n;

use Stringable;

/**
 * Turns an English source string into the current locale's text.
 *
 * The message *is* the key, so a miss is not an error to recover from: the
 * English literal is already the right answer, and an English render is the
 * documented fallback at every layer. `i18n:check` is what turns a miss
 * into a build failure, so a half-translated catalogue never ships.
 *
 * Not `final readonly` — the locale is request state, set once per request
 * by {@see \Spora\Core\Kernel} and read by every choke point afterwards.
 */
final class Translator
{
    /** Rendered in until {@see useLocale()} says otherwise. */
    public const FALLBACK_LOCALE = LocaleResolver::FALLBACK_LOCALE;

    /** vue-i18n's named-parameter syntax, so a PHP and a Vue catalogue read the same. */
    private const PLACEHOLDER_PATTERN = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    private string $locale = self::FALLBACK_LOCALE;

    public function __construct(private readonly CatalogLoader $catalogs) {}

    /**
     * The locale every later `trans()` resolves against.
     */
    public function useLocale(string $locale): void
    {
        $this->locale = trim($locale);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @param array<string, scalar|Stringable|null> $parameters Replaces `{name}` placeholders. A placeholder with no parameter is left as written, so a missing one shows rather than silently vanishing.
     */
    public function trans(string $message, array $parameters = []): string
    {
        if ($message === '') {
            return '';
        }

        $translated = $this->catalogs->messagesFor($this->locale)[$message] ?? $message;

        return $parameters === [] ? $translated : $this->interpolate($translated, $parameters);
    }

    /**
     * @param array<string, scalar|Stringable|null> $parameters
     */
    private function interpolate(string $message, array $parameters): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static fn(array $match): string => array_key_exists($match[1], $parameters)
                ? (string) $parameters[$match[1]]
                : $match[0],
            $message,
        );
    }
}
