<?php

declare(strict_types=1);

use Spora\Build\I18nCheckCommand;
use Symfony\Component\Console\Command\Command;

/**
 * The PHP half of the i18n CI contract.
 *
 * The rules are tested through the command's own rule methods rather than by
 * running `i18n:check` against a fixture tree: the command resolves its paths
 * from `BASE_PATH`, so a fixture tree is unreachable, and writing to the real
 * `locales/*.json` would race across the parallel test processes.
 *
 * The two rule sets added or rewritten during review — orphans, and the
 * explicit identical-value allow-list — are the ones most likely to be
 * "simplified" back into something that silently stops working, so they get
 * the most cases.
 */
describe('i18n:check rules', function (): void {
    describe('rule 2 — gaps', function (): void {
        it('reports a harvested key with no catalogue entry', function (): void {
            // gaps() returns a formatted block, so assert on content rather
            // than on the element count.
            $problems = i18nRule('gaps', 'de', ['Save' => ['f.php:1']], ['Something else' => 'Etwas anderes']);

            expect(implode("\n", $problems))->toContain('Save');
        });

        it('reports nothing when every harvested key is present', function (): void {
            $problems = i18nRule('gaps', 'de', ['Save' => ['f.php:1']], ['Save' => 'Speichern']);

            expect($problems)->toBe([]);
        });
    });

    describe('rule 2b — orphans', function (): void {
        it('reports a catalogue key the source no longer produces', function (): void {
            // Without this, a copy edit leaves the old entry behind and
            // nothing looks wrong — the key IS the English text, so an orphan
            // is indistinguishable from a live entry at a glance.
            $problems = i18nRule('orphans', 'de', ['Save' => ['f.php:1']], [
                'Save'   => 'Speichern',
                'Retired' => 'Veraltet',
            ]);

            expect($problems)->toHaveCount(1);
            expect(implode("\n", $problems))->toContain('Retired');
        });

        it('reports every orphan, not just the first', function (): void {
            $problems = i18nRule('orphans', 'de', [], ['A' => 'a', 'B' => 'b', 'C' => 'c']);

            expect($problems)->toHaveCount(1); // one message listing all three
            expect(implode("\n", $problems))->toContain('A', 'B', 'C');
        });

        it('reports nothing when the catalogue matches the source', function (): void {
            expect(i18nRule('orphans', 'de', ['Save' => ['f:1']], ['Save' => 'Speichern']))->toBe([]);
        });
    });

    describe('rule 3 — placeholders', function (): void {
        it('reports an empty value', function (): void {
            $problems = i18nRule('placeholders', 'de', ['Save' => 'Save'], ['Save' => '   ']);

            expect(implode("\n", $problems))->toContain('empty value');
        });

        it('reports a capitalised single word copied across verbatim', function (): void {
            // The regression this rule most easily loses. A shape heuristic
            // keyed on "contains a lowercase word" passed "Save", "Dashboard"
            // and "Settings" — which is the entire failure it exists to catch.
            foreach (['Save', 'Dashboard', 'Settings', 'Cancel'] as $key) {
                $problems = i18nRule('placeholders', 'de', [$key => $key], [$key => $key]);

                expect(implode("\n", $problems))->toContain('identical to the English source');
            }
        });

        it('reports an untranslated multi-word term', function (): void {
            $key   = 'Overwrite all agents';
            $value = 'Overwrite all agents';

            $problems = i18nRule('placeholders', 'de', [$key => $key], [$key => $value]);

            expect(implode("\n", $problems))->toContain('identical to the English source');
        });

        it('permits a proper noun that German spells identically', function (): void {
            // TLS, SQLite, Skill, Worker and friends have no correct
            // alternative; failing on them means inventing bad German.
            foreach (['TLS', 'SSL', 'SQLite', 'Skill', 'Agent', 'Worker', 'Debug', 'Info', 'Plugins'] as $term) {
                $problems = i18nRule('placeholders', 'de', [$term => $term], [$term => $term]);

                expect($problems)->toBe([], "expected {$term} to be permitted");
            }
        });

        it('does not warn or crash on a key missing from the English map', function (): void {
            // placeholders() reads $english[$key]; an unguarded read emits a
            // PHP warning and then compares against null.
            $problems = i18nRule('placeholders', 'de', [], ['Orphan' => 'Verwaist']);

            expect($problems)->toBe([]);
        });

        it('reports nothing for a properly translated catalogue', function (): void {
            $english = ['Save' => 'Save', 'TLS' => 'TLS'];
            $german  = ['Save' => 'Speichern', 'TLS' => 'TLS'];

            expect(i18nRule('placeholders', 'de', $english, $german))->toBe([]);
        });
    });

    describe('rule 4 — unsafe syntax', function (): void {
        it('reports a bare brace that is not a parameter slot', function (): void {
            // `{base_url}` is a legitimate slot for Translator::trans(); a
            // JSON snippet pasted into help text is not, and renders as
            // literal braces at runtime.
            $message = 'Payload looks like {"a": 1}';

            expect(i18nUnsafe($message))->not->toBe([]);
        });

        it('permits a named parameter slot', function (): void {
            expect(i18nUnsafe('POSTs to {base_url}/audio/transcriptions.'))->toBe([]);
        });

        it('permits a quoted escape sequence', function (): void {
            expect(i18nUnsafe("Use {'{'} and {'|'} literally"))->toBe([]);
        });
    });

    describe('rule 1 — drift', function (): void {
        it('reports a committed en.json that disagrees with the source', function (): void {
            // One logical problem, reported per key — a missing one and an
            // extra one, so more than one line comes back.
            expect(i18nDrift(['Save' => ['f:1']], ['Save' => 'Save', 'Gone' => 'Gone']))->not->toBe([]);
        });

        it('reports nothing when en.json matches the source exactly', function (): void {
            expect(i18nDrift(['Save' => ['f:1']], ['Save' => 'Save']))->toBe([]);
        });
    });

    describe('rule 5 — placeholder parity', function (): void {
        it('reports a translation that carries a placeholder the English does not', function (): void {
            // The captures are positional, so a second placeholder has
            // nothing to fill it with.
            $problems = i18nParity([
                "Create email folder '%s'" => "E-Mail-Ordner '%s' '%s' erstellen",
            ]);

            expect($problems)->toHaveCount(3);
            expect(implode("\n", $problems))->toContain('expected [%s], found [%s, %s]');
        });

        it('reports a translation that drops a placeholder', function (): void {
            $problems = i18nParity([
                "Create email folder '%s'" => 'E-Mail-Ordner erstellen',
            ]);

            expect(implode("\n", $problems))->toContain('expected [%s], found []');
        });

        it('reports placeholders in the wrong order', function (): void {
            // The counts match, so a count check would pass — but the
            // captures are substituted positionally, so the first slot
            // would take the second capture.
            $problems = i18nParity([
                'Expected %s answer(s), got %d.' => '%d Antwort(en) erwartet, %s erhalten.',
                'Update agent fields (%s).' => 'Agentenfelder (%d) aktualisieren.',
            ]);

            expect($problems)->toHaveCount(4);
            expect(implode("\n", $problems))->toContain('expected [%s, %d], found [%d, %s]');
        });

        it('accepts a literal percent sign', function (): void {
            // "50 % davon" is correct German, and a percent sign that is
            // not a placeholder is not part of the sequence.
            $problems = i18nParity([
                'Saved %s items' => '50 % von %s Artikeln gespeichert',
            ]);

            expect($problems)->toBe([]);
        });

        it('reports nothing when the placeholders match', function (): void {
            $problems = i18nParity([
                'Mark todo \'%s\' as %s.' => 'Todo \'%s\' als %s markieren.',
                'No placeholders here.'   => 'Keine Platzhalter hier.',
            ]);

            expect($problems)->toBe([]);
        });
    });
});

/** Invoke one of the command's rule methods through reflection. */
function i18nRule(string $method, string $locale, array $third, array $fourth): array
{
    $instance = new I18nCheckCommand();

    return (new ReflectionMethod($instance, $method))->invoke($instance, $locale, $third, $fourth);
}

function i18nUnsafe(string $message): array
{
    $instance = new I18nCheckCommand();

    return (new ReflectionMethod($instance, 'unsafeMessages'))->invoke($instance, [$message => [$message]]);
}

function i18nDrift(array $harvested, array $english): array
{
    $instance = new I18nCheckCommand();

    return (new ReflectionMethod($instance, 'drift'))->invoke($instance, $harvested, $english);
}

function i18nParity(array $catalogue): array
{
    $instance = new I18nCheckCommand();

    return (new ReflectionMethod($instance, 'placeholderParity'))->invoke($instance, 'de', $catalogue);
}

// Referenced so the import is meaningful to static analysis: the command
// under test is the one the CI composer script instantiates.
expect(class_exists(I18nCheckCommand::class))->toBeTrue()
    ->and(Command::SUCCESS)->toBe(0);
