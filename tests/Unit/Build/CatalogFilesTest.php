<?php

declare(strict_types=1);

use Spora\Build\CatalogFiles;

describe('CatalogFiles', function (): void {
    describe('paths', function (): void {
        it('puts en.json under locales/ at the repository root', function (): void {
            expect(CatalogFiles::englishPath())->toEndWith('/locales/en.json');
        });

        it('builds a target locale path from the same root', function (): void {
            expect(CatalogFiles::pathFor('de'))->toEndWith('/locales/de.json');
        });

        it('gives en.json and the built locale the same directory', function (): void {
            expect(dirname(CatalogFiles::englishPath()))
                ->toBe(dirname(CatalogFiles::pathFor('de')));
        });
    });

    describe('encode', function (): void {
        it('sorts by key so a regenerated file diffs cleanly', function (): void {
            $encoded = CatalogFiles::encode(['Zebra' => 'Zebra', 'Apple' => 'Apfel']);

            expect(array_keys(json_decode($encoded, true)))
                ->toBe(['Apple', 'Zebra']);
        });

        it('keeps slashes and non-ascii characters unescaped', function (): void {
            // Both would survive a round trip either way, but an escaped
            // `ä` in a German catalogue is a review-noise diff on every
            // regeneration.
            $encoded = CatalogFiles::encode(['Grüße / Tag' => 'Grüße / Tag']);

            expect($encoded)->toContain('Grüße / Tag');
            expect($encoded)->not->toContain('\u00e4');
        });

        it('ends with a trailing newline', function (): void {
            expect(CatalogFiles::encode(['a' => 'a']))->toEndWith("\n");
        });

        it('refuses to encode a value JSON cannot represent', function (): void {
            CatalogFiles::encode(['bad' => "\xB1\x31"]);
        })->throws(JsonException::class);
    });

    describe('read', function (): void {
        it('returns an empty map for a file that does not exist', function (): void {
            expect(CatalogFiles::read('/nonexistent/locales/fr.json'))->toBe([]);
        });

        it('reads a flat string map', function (): void {
            $path = writeTempJson(['Save' => 'Speichern']);

            expect(CatalogFiles::read($path))->toBe(['Save' => 'Speichern']);
        });

        it('drops a non-string value rather than coercing it', function (): void {
            // A hand-edited catalogue with the wrong shape should leave its
            // key visibly missing, so rule 2 reports it — not render the
            // literal word "Array" in the UI.
            $path = writeTempJsonRaw('{"Broken": ["a"], "Nested": {"x": "y"}}');

            expect(CatalogFiles::read($path))->toBe([]);
        });

        it('drops a numeric key rather than casting it back to a string', function (): void {
            // json_decode with assoc=true turns `"0"` into int 0, so the
            // `is_string($key)` guard rejects it. A catalogue is a string
            // map; a key that arrived as an int is malformed input.
            $path = writeTempJsonRaw('{"0": "Null", "Real": "Echt"}');

            expect(CatalogFiles::read($path))->toBe(['Real' => 'Echt']);
        });

        it('returns an empty map when the file is a JSON scalar', function (): void {
            $path = writeTempJsonRaw('"just a string"');

            expect(CatalogFiles::read($path))->toBe([]);
        });

        it('throws on malformed JSON rather than returning a partial map', function (): void {
            $path = tempnam(sys_get_temp_dir(), 'i18n') . '.json';
            file_put_contents($path, '{not json');

            CatalogFiles::read($path);
        })->throws(JsonException::class);
    });
});

/** Write a JSON file under the test temp dir and return its path. */
function writeTempJson(array $data): string
{
    return writeTempJsonRaw((string) json_encode($data));
}

function writeTempJsonRaw(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'i18n') . '.json';
    file_put_contents($path, $contents);

    return $path;
}
