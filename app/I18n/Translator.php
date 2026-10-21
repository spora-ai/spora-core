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

    /**
     * `sprintf`-style placeholders, which is what PHP's own tools use.
     *
     * The frontend's `{name}` slots are substituted by the caller and arrive
     * already filled; these are the ones that come pre-filled in the string.
     */
    private const TEMPLATE_PLACEHOLDERS = TemplatePlaceholders::SPECIFIERS;

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

        $catalogue = $this->catalogs->messagesFor($this->locale);
        $translated = $catalogue[$message] ?? $this->matchTemplate($catalogue, $message) ?? $message;

        return $parameters === [] ? $translated : $this->interpolate($translated, $parameters);
    }

    /**
     * Translate a string that was rendered from a template.
     *
     * A tool's `describeAction()` interpolates its arguments itself, so what
     * reaches the catalogue lookup is the *result* — `Create email folder
     * 'Archive'` — while the catalogue key is the template it came from,
     * `Create email folder '%s'`. An exact lookup cannot bridge that.
     *
     * So the rendered string is matched against the catalogue's templates
     * instead: each `%s` becomes a capture, each `%d` an integer, and the
     * captures are substituted back into the translated template. The
     * German therefore reads `E-Mail-Ordner 'Archive' erstellen` rather
     * than showing the placeholder.
     *
     * Only templates are considered, and only after an exact match has
     * failed — a plain string is never at risk of being matched by a
     * template. When two templates match equally well, neither is used:
     * a guess here would put the wrong sentence in front of an operator
     * approving a tool call.
     *
     * @param array<string, string> $catalogue
     */
    private function matchTemplate(array $catalogue, string $rendered): ?string
    {
        $best = null;
        $bestScore = -1;
        $ambiguous = false;

        foreach ($catalogue as $key => $value) {
            if (!str_contains($key, '%')) {
                continue;
            }

            $pattern = $this->templatePattern($key);
            if ($pattern === null || preg_match($pattern, $rendered, $matches) !== 1) {
                continue;
            }

            // Prefer the most specific template: the one with the most
            // literal text around its placeholders.
            $score = strlen(str_replace(self::TEMPLATE_PLACEHOLDERS, '', $key));
            if ($score > $bestScore) {
                $best = [$key, $value, $matches];
                $bestScore = $score;
                $ambiguous = false;
            } elseif ($score === $bestScore && $best[1] !== $value) {
                $ambiguous = true;
            }
        }

        if ($best === null || $ambiguous) {
            return null;
        }

        $captures = array_slice($best[2], 1);

        $translated = $this->fillTemplate($best[1], $captures);
        if ($translated !== null) {
            return $translated;
        }

        // The translation does not have the placeholders the English has.
        // The English is the fallback at every layer, and its placeholders
        // are the ones the captures were taken from.
        return $this->fillTemplate($best[0], $captures);
    }

    /**
     * Fill a template's placeholders with the captured values, in order.
     *
     * Only `%s` and `%d` are placeholders, so a percent sign in the copy
     * survives as written — "50 % davon" is not a format specifier.
     *
     * @param list<string> $captures
     */
    private function fillTemplate(string $template, array $captures): ?string
    {
        if (count(TemplatePlaceholders::in($template)) !== count($captures)) {
            return null;
        }

        $parts = preg_split(TemplatePlaceholders::PATTERN, $template);
        if ($parts === false) {
            return null;
        }

        $filled = $parts[0] ?? '';
        foreach ($captures as $index => $capture) {
            $filled .= $capture . ($parts[$index + 1] ?? '');
        }

        return $filled;
    }

    /**
     * A matcher for one template, or null when it is not usable.
     */
    private function templatePattern(string $template): ?string
    {
        // Every `%` must be one of the known placeholders. A `%` that is
        // not — an unknown format specifier, or a literal percent sign —
        // would mean guessing at the shape of the string.
        $known = substr_count($template, '%s') + substr_count($template, '%d');
        if (substr_count($template, '%') !== $known) {
            return null;
        }

        $quoted = preg_quote($template, '/');

        $pattern = str_replace(
            ['%s', '%d'],
            ['(.+?)', '(-?\d+)'],
            $quoted,
        );

        return '/^' . $pattern . '$/u';
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
