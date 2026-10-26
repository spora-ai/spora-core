<?php

declare(strict_types=1);

namespace Spora\Build;

use JsonException;
use Spora\I18n\CatalogLoader;
use Spora\I18n\TemplatePlaceholders;
use Spora\I18n\Translator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/spora-build i18n:check` — the PHP half of the i18n CI contract.
 *
 * The rules the gate enforces:
 *
 *   1. `locales/en.json` drifted from the harvest.
 *   2. A harvested message has no entry in a target catalogue.
 *   2b. A key in a target catalogue that the harvest no longer produces.
 *   3. A target value is empty or identical to its English source.
 *   4. A message contains an unescaped `{`, `}` or `|`.
 *   5. A target value does not carry the `%s`/`%d` placeholders of its
 *      English source, in the same order.
 *   6. A target entry the runtime cannot reach: the template it is keyed by
 *      never matches a rendered message.
 */
#[AsCommand(
    name: 'i18n:check',
    description: 'Fail when a translation catalogue is stale, incomplete or untranslated.',
)]
final class I18nCheckCommand extends Command
{
    /**
     * The locales `i18n:check` enforces as translation targets: `en` is the
     * generated reference catalogue, checked for drift (rule 1) but never translated.
     */
    private const TARGET_LOCALES = ['de'];

    /** The synthetic `%s` argument: distinctive, so it cannot collide with a real catalogue key. */
    private const PROBE = 'spora-i18n-probe';

    /** The same argument carrying a line break — the one the matcher has to survive. */
    private const PROBE_MULTILINE = "spora-i18n-probe\nspora-i18n-probe-second-line";

    /** The synthetic `%d` argument — the matcher only accepts digits there. */
    private const PROBE_NUMBER = '42';

    /**
     * The reason a word is allowed to keep its English spelling: German
     * uses the same word, so a translation would be a different word.
     */
    private const DOMAIN_TERM = 'Domain term, identical in German.';

    /**
     * The only English sources a catalogue may leave untranslated, because
     * German spells them identically. Anything not listed here must have a
     * German value that differs from its source.
     *
     * An earlier version of this rule used a shape heuristic — "fail only if
     * the source contains a lowercase word of three or more letters" — to
     * avoid an allow-list. It was strictly worse. Every entry below is
     * capitalised or an acronym, so the heuristic let every one of them
     * through, along with exactly the failures the rule exists to catch:
     * a `Save` or `Dashboard` copied across verbatim would have shipped to a
     * German install. The set is small, closed, and grows in one place, which
     * is a better trade than a pattern that cannot tell `TLS` from `Save`.
     */
    private const IDENTICAL_ALLOWED = [
        'Agent'     => self::DOMAIN_TERM,
        'Debug'     => 'Identical; "Debug-Modus" would be over-translation.',
        'Info'      => 'Identical; "Information" changes the register.',
        'MySQL / MariaDB' => 'Product names.',
        'Plugins'   => 'Established loan in German usage.',
        'Skill'     => self::DOMAIN_TERM,
        'SQLite'    => 'Product name.',
        'SSL'       => 'Protocol acronym.',
        'TLS'       => 'Protocol acronym.',
        'Worker'    => self::DOMAIN_TERM,
    ];

    /**
     * vue-i18n compiles a bare `{`, `}` or `|` as interpolation and plural
     * syntax and throws at render, and {@see \Spora\I18n\Translator} treats
     * `{name}` as a parameter slot. So a `{identifier}` is legitimate on
     * both sides and `{'{'}` is vue-i18n's escape for a literal brace —
     * anything else carrying one of the three characters is a copy snippet
     * that breaks at render.
     */
    private const ALLOWED_BRACE_SYNTAX = '/\{\'[a-z{}|]\'\}|\{[A-Za-z_][A-Za-z0-9_]*\}/';

    /**
     * Whether a harvested message would throw when vue-i18n compiles it.
     */
    public static function unsafeMessage(string $message): bool
    {
        $stripped = (string) preg_replace(self::ALLOWED_BRACE_SYNTAX, '', $message);

        return str_contains($stripped, '{') || str_contains($stripped, '}') || str_contains($stripped, '|');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $harvested = (new CatalogHarvester(CatalogFiles::basePath()))->harvest();
            $english = CatalogFiles::read(CatalogFiles::englishPath());
            $targets = [];
            foreach (self::TARGET_LOCALES as $locale) {
                $targets[$locale] = CatalogFiles::read(CatalogFiles::pathFor($locale));
            }
        } catch (JsonException $e) {
            $io->error(sprintf('Failed to read a catalogue: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $problems = array_merge(
            $this->drift($harvested, $english),
            $this->unsafeMessages($harvested),
        );

        foreach ($targets as $locale => $catalogue) {
            $problems = array_merge($problems, $this->gaps($locale, $harvested, $catalogue));
            $problems = array_merge($problems, $this->orphans($locale, $harvested, $catalogue));
            $problems = array_merge($problems, $this->placeholders($locale, $english, $catalogue));
            $problems = array_merge($problems, $this->placeholderParity($locale, $catalogue));
            $problems = array_merge($problems, $this->unreachable($locale, $catalogue, CatalogFiles::pathFor($locale)));
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $io->writeln($problem);
            }
            $io->error(sprintf('i18n:check failed with %d problem(s).', count($problems)));
            return Command::FAILURE;
        }

        $io->success(sprintf('All catalogues are complete (%d messages).', count($harvested)));

        return Command::SUCCESS;
    }

    /**
     * Rule 1 — the generated file is the source of truth for the key set,
     * so a key that appeared or disappeared in the source without a
     * regeneration is a build failure, not a stale artefact.
     *
     * @param array<string, list<string>> $harvested
     * @param array<string, string>       $english
     * @return list<string>
     */
    private function drift(array $harvested, array $english): array
    {
        $fromSource = array_keys($harvested);
        sort($fromSource);
        $committed = array_keys($english);
        sort($committed);

        if ($fromSource === $committed) {
            return [];
        }

        return [
            '',
            'locales/en.json has drifted from the source. Run: composer i18n:extract',
            sprintf('  %d harvested, %d committed.', count($fromSource), count($committed)),
        ];
    }

    /**
     * Rule 4 — checked against the harvest rather than the catalogue, so a
     * brace that would throw at render is reported whether or not anyone
     * has translated the key yet.
     *
     * @param array<string, list<string>> $harvested
     * @return list<string>
     */
    private function unsafeMessages(array $harvested): array
    {
        $lines = [];

        foreach ($harvested as $message => $locators) {
            if (!self::unsafeMessage($message)) {
                continue;
            }

            $lines[] = sprintf(
                '  %s' . PHP_EOL . '    "%s"' . PHP_EOL . '    vue-i18n compiles { } and | as interpolation and plural syntax and throws at render.',
                implode(', ', array_slice($locators, 0, 3)),
                $message,
            );
        }

        return $lines === [] ? [] : array_merge(['', 'Messages containing unescaped { } or |:'], $lines);
    }

    /**
     * Rule 2 — every harvested message is required: a half-translated error
     * screen reads as a bug rather than as work in progress.
     *
     * @param array<string, list<string>> $harvested
     * @param array<string, string>       $catalogue
     * @return list<string>
     */
    private function gaps(string $locale, array $harvested, array $catalogue): array
    {
        $missing = array_values(array_filter(
            array_keys($harvested),
            static fn(string $key): bool => !array_key_exists($key, $catalogue),
        ));

        if ($missing === []) {
            return [];
        }

        $lines = array_map(function (string $key) use ($harvested): string {
            return sprintf(
                '  %s' . PHP_EOL . '    "%s"',
                implode(', ', array_slice($harvested[$key], 0, 3)),
                $key,
            );
        }, $missing);

        return array_merge(
            ['', sprintf('%s is missing %d translation(s):', $locale, count($missing))],
            $lines,
            ['  → add to locales/' . $locale . '.json, or run: composer i18n:extract'],
        );
    }

    /**
     * Rule 2b — a key in the catalogue that the source no longer produces.
     *
     * Rule 2 catches the new English the moment it is missing; nothing
     * else would notice the *old* entry, which then sits in `de.json` with
     * nothing referencing it — and because the key is the English text,
     * nothing looks wrong. Without this rule a typo'd or hand-added key is
     * indistinguishable from a real one, and the catalogue grows without
     * bound across a migration.
     *
     * @param array<string, string> $catalogue
     * @param array<string, list<string>> $harvested
     * @return list<string>
     */
    private function orphans(string $locale, array $harvested, array $catalogue): array
    {
        $orphans = array_diff(array_keys($catalogue), array_keys($harvested));
        if ($orphans === []) {
            return [];
        }

        sort($orphans);

        return array_map(
            static fn(string $key): string => sprintf(
                '%s: %d orphaned key(s) not found in the source; remove them from %s',
                $locale,
                count($orphans),
                basename(CatalogFiles::pathFor($locale)),
            ) . "\n" . implode("\n", array_map(
                static fn(string $k): string => sprintf('  %s', $k),
                $orphans,
            )),
            [''],
        );
    }

    /**
     * Rule 3 — an English-identical value is a placeholder, not a
     * translation, and the reason it matters is that it satisfies rule 2
     * while rendering as English in production.
     *
     * @param array<string, string> $english
     * @param array<string, string> $catalogue
     * @return list<string>
     */
    private function placeholders(string $locale, array $english, array $catalogue): array
    {
        $untranslated = [];
        foreach ($catalogue as $key => $value) {
            // A key the source no longer produces is rule 2b's business. Read
            // it as `null` rather than indexing blindly: an orphan must not
            // also emit a PHP warning, and `null` can never equal the value.
            $source = $english[$key] ?? null;
            $reason = match (true) {
                trim($value) === '' => 'empty value',
                $source !== null && $value === $source && !isset(self::IDENTICAL_ALLOWED[$key])
                    => 'value is identical to the English source',
                default => null,
            };

            if ($reason !== null) {
                $untranslated[$key] = $reason;
            }
        }

        if ($untranslated === []) {
            return [];
        }

        $lines = [];
        foreach ($untranslated as $key => $reason) {
            $lines[] = sprintf('  "%s" — %s', $key, $reason);
        }

        return array_merge(
            ['', sprintf('%s has %d untranslated placeholder(s):', $locale, count($untranslated))],
            $lines,
        );
    }

    /**
     * Rule 5 — a translation must carry the placeholders of its English
     * source, in the same order.
     *
     * The captures are substituted positionally: the first `%s` or `%d` of
     * the translation takes the first capture of the English template, and
     * the next one takes the next. A translation that drops a placeholder
     * therefore renders the English instead, and one that swaps two of
     * them fills the wrong slot — both read as a broken screen rather
     * than as a mistranslation, which is why the catalogue has to carry
     * the placeholders unchanged.
     *
     * The comparison is on the ordered sequence and on the type of every
     * placeholder: `%s` where the English had `%d` is wrong even though
     * the counts match.
     *
     * @param array<string, string> $catalogue
     * @return list<string>
     */
    private function placeholderParity(string $locale, array $catalogue): array
    {
        $mismatched = [];

        foreach ($catalogue as $key => $value) {
            $expected = TemplatePlaceholders::in($key);
            $found = TemplatePlaceholders::in($value);

            if ($expected === $found) {
                continue;
            }

            $mismatched[$key] = [$expected, $found];
        }

        if ($mismatched === []) {
            return [];
        }

        $lines = [];
        foreach ($mismatched as $key => [$expected, $found]) {
            $lines[] = sprintf(
                '  "%s" — expected [%s], found [%s]',
                $key,
                implode(', ', $expected),
                implode(', ', $found),
            );
        }

        return array_merge(
            ['', sprintf('%s has %d placeholder mismatch(es):', $locale, count($mismatched))],
            $lines,
        );
    }

    /**
     * Rule 6 — an entry the runtime cannot reach.
     *
     * The rules above read the catalogue as data; none can see that the
     * *matcher* never matches. A template whose argument carries a line
     * break never reaches its German value, so `trans()` answers with the
     * English source. The entry is rendered with a synthetic argument and
     * looked up through the real {@see \Spora\I18n\Translator}: the German
     * value has to come back.
     *
     * The lookup runs twice per template — a single-line argument and one that
     * crosses a line break; a matcher that cannot cross one fails the second.
     *
     * Only the `%s`/`%d` templates can be checked this way: a plain key is
     * reached by an exact lookup, and a `{name}` key is substituted by the
     * caller, so the catalogue holds the template, not the message.
     *
     * @param array<string, string> $catalogue
     * @param string                $cataloguePath The file the catalogue was read from; the runtime lookup runs against that very file.
     * @return list<string>
     */
    private function unreachable(string $locale, array $catalogue, string $cataloguePath): array
    {
        $translator = new Translator(new CatalogLoader([], dirname($cataloguePath)));
        $translator->useLocale($locale);

        $lines = [];

        foreach ($catalogue as $key => $value) {
            $placeholders = TemplatePlaceholders::in($key);

            if ($placeholders === []) {
                continue;
            }

            // Rule 5's business: without the placeholders there is no
            // expected rendering to compare against.
            if (TemplatePlaceholders::in($value) !== $placeholders) {
                continue;
            }

            foreach ([self::PROBE, self::PROBE_MULTILINE] as $probe) {
                $arguments = $this->argumentsFor($placeholders, $probe);
                $rendered = self::render($key, $arguments);
                $expected = self::render($value, $arguments);

                if ($translator->trans($rendered) === $expected) {
                    continue;
                }

                $lines[] = sprintf(
                    '  "%s" — unreachable: the template does not match the message it is rendered into.',
                    $key,
                );
            }
        }

        if ($lines === []) {
            return [];
        }

        return array_merge(
            ['', sprintf('%s has %d unreachable translation(s):', $locale, count($lines))],
            $lines,
        );
    }

    /**
     * The synthetic arguments for a template's placeholders, in order: a
     * `%d` placeholder gets a number, a `%s` placeholder gets the probe — so
     * the line-breaking argument rides on the string placeholder.
     *
     * @param list<string> $placeholders
     * @return list<string>
     */
    private function argumentsFor(array $placeholders, string $probe): array
    {
        return array_map(
            static fn(string $placeholder): string => $placeholder === '%d' ? self::PROBE_NUMBER : $probe,
            $placeholders,
        );
    }

    /**
     * Substitute the arguments into a template, positionally.
     *
     * @param list<string> $arguments
     */
    private static function render(string $template, array $arguments): string
    {
        $parts = preg_split(TemplatePlaceholders::PATTERN, $template);
        if ($parts === false) {
            return $template;
        }

        $rendered = $parts[0] ?? '';
        foreach ($arguments as $index => $argument) {
            $rendered .= $argument . ($parts[$index + 1] ?? '');
        }

        return $rendered;
    }
}
