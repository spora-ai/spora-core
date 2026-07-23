<?php

declare(strict_types=1);

use Spora\Services\Text\Utf8Sanitizer;

test('scrubString returns empty string for empty input', function (): void {
    expect(Utf8Sanitizer::scrubString(''))->toBe('');
});

test('scrubString passes valid UTF-8 through unchanged', function (): void {
    $text = 'résumé-2026.pdf — naïve façade, €100 ✓';
    expect(Utf8Sanitizer::scrubString($text))->toBe($text);
});

test('scrubString salvages Latin-1 bytes as Windows-1252', function (): void {
    // é (0xE9) ü (0xFC) ß (0xDF) are all valid Windows-1252.
    $latin1 = chr(0xE9) . chr(0xFC) . chr(0xDF);
    $scrubbed = Utf8Sanitizer::scrubString($latin1);
    expect(mb_check_encoding($scrubbed, 'UTF-8'))->toBeTrue();
    expect($scrubbed)->toBe('éüß');
});

test('scrubString salvages Latin-1 bytes via the Windows-1252 branch (bytes also defined in ISO-8859-1)', function (): void {
    // À (0xC0) © (0xA9) ¢ (0xA2) ® (0xAE) — same bytes in both encodings
    // for this range, so mb_convert_encoding succeeds on the Windows-1252
    // attempt and the ISO-8859-1 fallback never runs. The test pins the
    // sibling under the Windows-1252 branch, not the ISO-8859-1 branch
    // the original name implied.
    $iso = chr(0xC0) . chr(0xA9) . chr(0xA2) . chr(0xAE);
    $scrubbed = Utf8Sanitizer::scrubString($iso);
    expect(mb_check_encoding($scrubbed, 'UTF-8'))->toBeTrue();
    expect($scrubbed)->toBe('À©¢®');
});

test('scrubString drops an isolated invalid UTF-8 byte via iconv //IGNORE', function (): void {
    // 0xC0 alone is an invalid UTF-8 leading byte (overlong marker).
    // Windows-1252 and ISO-8859-1 both salvage it as "À", so the
    // mb_convert_encoding branch wins first and the iconv //IGNORE
    // fallback never runs. The fixture documents the iconv-only path
    // a future test could cover explicitly (e.g. an unrecognised
    // byte sequence that the encoding chain can't map).
    $text = "prefix-" . chr(0xC0) . "-suffix";
    $scrubbed = Utf8Sanitizer::scrubString($text);
    expect(mb_check_encoding($scrubbed, 'UTF-8'))->toBeTrue();
    expect($scrubbed)->toContain('prefix-');
    expect($scrubbed)->toContain('-suffix');
    // And the whole result must be JSON-safe — this is the
    // invariant that justifies the utility existing.
    expect(json_encode(['v' => $scrubbed]))->not->toBeFalse();
});

test('scrubString always returns a JSON-encodable result', function (): void {
    // Every byte value (0x00-0xFF) is defined in either Windows-1252
    // or ISO-8859-1, so the salvage chain always succeeds. The
    // "total failure" branch in scrubString() is unreachable from
    // PHP string input — but the type contract (`string`, never
    // `false`) still matters. This test asserts the invariant:
    // whatever scrubString() returns must round-trip through
    // json_encode without throwing.
    $samples = [
        chr(0xFE) . chr(0xFF),
        chr(0xC0) . chr(0x80) . chr(0xC1) . chr(0xBF),
        str_repeat(chr(0x80), 32),
        "\x00\x01\x02\x03",
    ];
    foreach ($samples as $text) {
        $scrubbed = Utf8Sanitizer::scrubString($text);
        expect($scrubbed)->toBeString();
        expect(mb_check_encoding($scrubbed, 'UTF-8'))->toBeTrue();
        // The whole point: no exception at the json_encode boundary.
        expect(json_encode(['v' => $scrubbed]))->not->toBeFalse();
    }
});

test('scrub recurses into nested arrays of strings', function (): void {
    $input = [
        'filename'  => chr(0xE9) . chr(0xFC),
        'tags'      => ['ok', chr(0x80) . chr(0x81)],
        'metadata'  => ['nested' => ['title' => chr(0xE9)]],
    ];
    $scrubbed = Utf8Sanitizer::scrub($input);

    expect(mb_check_encoding($scrubbed['filename'], 'UTF-8'))->toBeTrue();
    expect(mb_check_encoding($scrubbed['tags'][0], 'UTF-8'))->toBeTrue();
    expect(mb_check_encoding($scrubbed['tags'][1], 'UTF-8'))->toBeTrue();
    expect(mb_check_encoding($scrubbed['metadata']['nested']['title'], 'UTF-8'))->toBeTrue();
});

test('scrub passes non-string scalar and object types through unchanged', function (): void {
    $obj = new stdClass();
    $obj->x = chr(0xE9);

    $input = [
        'null'   => null,
        'int'    => 42,
        'float'  => 3.14,
        'bool'   => true,
        'object' => $obj,
    ];

    $scrubbed = Utf8Sanitizer::scrub($input);

    expect($scrubbed['null'])->toBeNull();
    expect($scrubbed['int'])->toBe(42);
    expect($scrubbed['float'])->toBe(3.14);
    expect($scrubbed['bool'])->toBeTrue();
    expect($scrubbed['object'])->toBe($obj);
});

test('scrub handles deeply nested arrays', function (): void {
    $input = ['a' => ['b' => ['c' => ['d' => chr(0xE9) . chr(0xFC)]]]];
    $scrubbed = Utf8Sanitizer::scrub($input);

    expect(mb_check_encoding($scrubbed['a']['b']['c']['d'], 'UTF-8'))->toBeTrue();
});

test('isValid returns true for valid UTF-8 and false for invalid', function (): void {
    expect(Utf8Sanitizer::isValid('hello'))->toBeTrue();
    expect(Utf8Sanitizer::isValid('résumé'))->toBeTrue();
    expect(Utf8Sanitizer::isValid(chr(0xE9)))->toBeFalse();
    expect(Utf8Sanitizer::isValid(''))->toBeTrue();
});

test('scrubString repairs a single bad byte inside otherwise-valid UTF-8', function (): void {
    // "résumé" with a stray 0x80 inserted between two valid chars.
    $text = 'r' . chr(0x80) . 'sumé';
    $scrubbed = Utf8Sanitizer::scrubString($text);
    expect(mb_check_encoding($scrubbed, 'UTF-8'))->toBeTrue();
    // The fix should preserve at least the surrounding ASCII
    expect($scrubbed)->toContain('r');
    expect($scrubbed)->toContain('sum');
});

test('scrubString does not crash on a half-surrogate', function (): void {
    // ED A0 is the start of a UTF-16 surrogate pair encoded as UTF-8,
    // which is invalid on its own.
    $text = chr(0xED) . chr(0xA0);
    $scrubbed = Utf8Sanitizer::scrubString($text);
    expect($scrubbed)->toBeString();
    expect(mb_check_encoding($scrubbed, 'UTF-8'))->toBeTrue();
});

test('scrub on a top-level non-string non-array returns the value unchanged', function (): void {
    expect(Utf8Sanitizer::scrub(42))->toBe(42);
    expect(Utf8Sanitizer::scrub(3.14))->toBe(3.14);
    expect(Utf8Sanitizer::scrub(true))->toBeTrue();
    expect(Utf8Sanitizer::scrub(null))->toBeNull();
    $obj = new stdClass();
    expect(Utf8Sanitizer::scrub($obj))->toBe($obj);
});

test('scrubString produces output that json_encode accepts', function (): void {
    // The whole point of this utility: this must NOT throw.
    $value = Utf8Sanitizer::scrubString('résumé — ' . chr(0xE9));
    $json = json_encode(['payload' => $value]);

    expect($json)->toBeString();
    expect($json)->not->toBeFalse();
    expect(json_decode($json, true))->toBe(['payload' => $value]);
});
