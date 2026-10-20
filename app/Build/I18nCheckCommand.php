<?php

declare(strict_types=1);

namespace Spora\Build;

use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/spora-build i18n:check` — the PHP half of the i18n CI contract.
 *
 * Four rules, in the order they are cheapest to explain:
 *
 *   1. `locales/en.json` drifted from the source — run
 *      `composer i18n:extract` and commit the result.
 *   2. A key in `en.json` has no entry in a target catalogue. Every
 *      harvested message is required: a half-translated error screen reads
 *      as a bug rather than as work in progress.
 *   3. A target value is empty or identical to its English source — an
 *      untranslated placeholder that would render as English in production
 *      while passing every other check.
 *   4. A message contains an unescaped `{`, `}` or `|`. vue-i18n compiles
 *      those as interpolation and plural syntax and throws at render, so a
 *      copy snippet containing braces is a runtime failure, not a typo.
 *
 * Rule "raw text outside `$t()`" from the workspace contract is a frontend
 * rule and has no PHP analogue: the harvest here is by construction, so a
 * string that reaches the Translator is a catalogue key by definition.
 */
#[AsCommand(
    name: 'i18n:check',
    description: 'Fail when a translation catalogue is stale, incomplete or untranslated.',
)]
final class I18nCheckCommand extends Command
{
    /** The locales `i18n:check` enforces, minus `en` (generated, never checked). */
    private const TARGET_LOCALES = ['de'];

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
     * capitalised or an acronym, so the heuristic let all ten of them
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
     * Rule 2.
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
     * Rule 2 covers `en \ <lang>`, so a copy edit is caught the moment the
     * new English is missing. It says nothing about the *old* entry, which
     * then sits in `de.json` with nothing referencing it — and because the
     * key is the English text, nothing looks wrong. Without this rule a
     * typo'd or hand-added key is indistinguishable from a real one, and
     * the catalogue grows without bound across a migration.
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
     * Rule 3. An English-identical value is a placeholder, not a
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
}
