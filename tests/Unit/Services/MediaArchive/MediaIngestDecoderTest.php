<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use InvalidArgumentException;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MediaIngestRequest;

/**
 * Direct unit coverage for {@see MediaIngestDecoder}. The integration
 * tests in `tests/Feature/MediaArchive/MediaArchiveServiceTest.php`
 * exercise the decoder through `MediaArchiveService::ingest()`; this
 * file pins down the per-branch behaviour of the decoder itself —
 * bytes passthrough, hex round-trip, base64 round-trip, and the
 * error paths for malformed payloads.
 */
function makeDecoder(): MediaIngestDecoder
{
    return new MediaIngestDecoder();
}

describe('MediaIngestDecoder::decodeHex', function (): void {
    it('decodes a valid hex string back to its bytes', function (): void {
        $bytes = "Hello\n";
        $hex = bin2hex($bytes);

        expect(makeDecoder()->decodeHex($hex))->toBe($bytes);
    });

    it('throws on hex strings with odd length', function (): void {
        makeDecoder()->decodeHex('abc');
    })->throws(InvalidArgumentException::class, 'odd length');

    it('throws on hex strings containing non-hex characters', function (): void {
        makeDecoder()->decodeHex('zzzz');
    })->throws(InvalidArgumentException::class, 'not valid hex');

    it('round-trips an empty payload (length is even)', function (): void {
        expect(makeDecoder()->decodeHex(''))->toBe('');
    });

    it('handles the full byte range (0x00–0xFF)', function (): void {
        $bytes = '';
        for ($i = 0; $i < 256; $i++) {
            $bytes .= chr($i);
        }
        $hex = bin2hex($bytes);

        expect(makeDecoder()->decodeHex($hex))->toBe($bytes);
    });
});

describe('MediaIngestDecoder::decodeBase64', function (): void {
    it('decodes a valid base64 string back to its bytes', function (): void {
        $bytes = 'Spora media archive';
        $b64 = base64_encode($bytes);

        expect(makeDecoder()->decodeBase64($b64))->toBe($bytes);
    });

    it('throws on invalid base64 (strict mode)', function (): void {
        // Contains a character outside the base64 alphabet.
        makeDecoder()->decodeBase64('not base64!');
    })->throws(InvalidArgumentException::class, 'not valid base64');

    it('throws on base64 with incorrect padding', function (): void {
        // Trailing characters that fail strict decoding.
        makeDecoder()->decodeBase64('AAA=A');
    })->throws(InvalidArgumentException::class, 'not valid base64');

    it('decodes an empty payload to an empty string', function (): void {
        expect(makeDecoder()->decodeBase64(''))->toBe('');
    });
});

describe('MediaIngestDecoder::decodeInline', function (): void {
    it('returns the raw bytes when bytes is set', function (): void {
        $bytes = 'raw bytes payload';
        $request = new MediaIngestRequest(bytes: $bytes);

        expect(makeDecoder()->decodeInline($request))->toBe($bytes);
    });

    it('decodes hex when hex is set', function (): void {
        $bytes = 'hex payload';
        $request = new MediaIngestRequest(hex: bin2hex($bytes));

        expect(makeDecoder()->decodeInline($request))->toBe($bytes);
    });

    it('decodes base64 when base64 is set', function (): void {
        $bytes = 'base64 payload';
        $request = new MediaIngestRequest(base64: base64_encode($bytes));

        expect(makeDecoder()->decodeInline($request))->toBe($bytes);
    });

    it('returns null when only url is set (URL is not inline)', function (): void {
        $request = new MediaIngestRequest(url: 'https://example.com/asset.png');

        expect(makeDecoder()->decodeInline($request))->toBeNull();
    });

    it('throws InvalidArgumentException when hex is malformed', function (): void {
        $request = new MediaIngestRequest(hex: 'abc');

        makeDecoder()->decodeInline($request);
    })->throws(InvalidArgumentException::class);

    it('throws InvalidArgumentException when base64 is malformed', function (): void {
        $request = new MediaIngestRequest(base64: 'not base64!');

        makeDecoder()->decodeInline($request);
    })->throws(InvalidArgumentException::class);
});
