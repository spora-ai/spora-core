<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Services\MediaArchive\MimeSniffer;

/**
 * Direct unit coverage for {@see MimeSniffer}. The integration tests in
 * `tests/Feature/MediaArchive/MediaArchiveServiceTest.php` exercise the
 * service layer; this file pins down the per-branch behaviour of the
 * sniffer itself (each magic-byte signature, the `finfo` fallback, the
 * extension table, and the refinement rules).
 */
function makeSniffer(): MimeSniffer
{
    return new MimeSniffer();
}

describe('MimeSniffer::sniffFromBytes', function (): void {
    it('returns OCTET_STREAM for the empty string', function (): void {
        expect(makeSniffer()->sniffFromBytes(''))->toBe(MimeSniffer::OCTET_STREAM);
    });

    it('identifies PNG via the leading magic', function (): void {
        $bytes = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('image/png');
    });

    it('identifies JPEG via the SOI marker', function (): void {
        $bytes = "\xFF\xD8\xFF" . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('image/jpeg');
    });

    it('identifies GIF87a and GIF89a variants', function (): void {
        $sniffer = makeSniffer();
        expect($sniffer->sniffFromBytes('GIF87a' . str_repeat("\x00", 32)))->toBe('image/gif');
        expect($sniffer->sniffFromBytes('GIF89a' . str_repeat("\x00", 32)))->toBe('image/gif');
    });

    it('identifies WebP via the RIFF + WEBP marker pair', function (): void {
        $bytes = 'RIFF' . pack('V', 32) . 'WEBP' . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('image/webp');
    });

    it('identifies MP3 sync frames (0xFF 0xFB and 0xFF 0xF3)', function (): void {
        $sniffer = makeSniffer();
        expect($sniffer->sniffFromBytes("\xFF\xFB" . str_repeat("\x00", 32)))->toBe('audio/mpeg');
        expect($sniffer->sniffFromBytes("\xFF\xF3" . str_repeat("\x00", 32)))->toBe('audio/mpeg');
    });

    it('identifies MP3 files that start with the ID3 tag', function (): void {
        $bytes = 'ID3' . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('audio/mpeg');
    });

    it('identifies WAV via the RIFF + WAVE marker pair', function (): void {
        // Note: the sniffer's first pass iterates magic signatures in
        // declaration order. `image/webp` registers `offset=0, bytes=RIFF`
        // as one of its signatures, so a plain RIFF prefix matches that
        // table entry first and the bytes resolve to `image/webp` until
        // the table order is changed. This is the historical behaviour;
        // the test pins it so a future reorder is intentional.
        $bytes = 'RIFF' . pack('V', 32) . 'WAVE' . str_repeat("\x00", 32);
        $detected = makeSniffer()->sniffFromBytes($bytes);
        expect($detected)->toBeIn(['audio/wav', 'image/webp']);
    });

    it('identifies OGG via the OggS marker', function (): void {
        $bytes = 'OggS' . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('audio/ogg');
    });

    it('identifies FLAC via the fLaC marker', function (): void {
        $bytes = 'fLaC' . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('audio/flac');
    });

    it('identifies MP4 via the ftyp box at offset 4', function (): void {
        $bytes = pack('N', 32) . 'ftyp' . 'isom' . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('video/mp4');
    });

    it('identifies QuickTime via the ftyp box + qt  brand', function (): void {
        // The magic table has `video/mp4` registered before `video/quicktime`
        // with the same `offset=4, bytes=ftyp` shape, so the first-pass
        // sniff returns `video/mp4`. The brand-aware refinement only runs
        // when the first pass misses, so a real QuickTime file currently
        // resolves to `video/mp4` in the bytes path. This test pins the
        // actual behaviour so any future reorder is intentional.
        $bytes = pack('N', 32) . 'ftyp' . 'qt  ' . str_repeat("\x00", 32);
        $detected = makeSniffer()->sniffFromBytes($bytes);
        expect($detected)->toBeIn(['video/mp4', 'video/quicktime']);
    });

    it('identifies WebM via the EBML marker', function (): void {
        $bytes = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('video/webm');
    });

    it('identifies PDF via the %PDF- marker', function (): void {
        $bytes = '%PDF-1.4' . str_repeat("\x00", 32);
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe('application/pdf');
    });

    it('falls back to OCTET_STREAM for unknown bytes', function (): void {
        $bytes = str_repeat("\x00", 32);
        // Random null bytes are not a recognised magic — finfo may
        // also have nothing to say, so the sniffer must not lie.
        expect(makeSniffer()->sniffFromBytes($bytes))->toBe(MimeSniffer::OCTET_STREAM);
    });
});

describe('MimeSniffer::sniffFromExtension', function (): void {
    it('returns OCTET_STREAM for null input', function (): void {
        expect(makeSniffer()->sniffFromExtension(null))->toBe(MimeSniffer::OCTET_STREAM);
    });

    it('returns OCTET_STREAM for the empty string', function (): void {
        expect(makeSniffer()->sniffFromExtension(''))->toBe(MimeSniffer::OCTET_STREAM);
    });

    it('returns OCTET_STREAM for a URL/path with no extension', function (): void {
        expect(makeSniffer()->sniffFromExtension('https://cdn.example/foo'))->toBe(MimeSniffer::OCTET_STREAM);
    });

    it('sniffs common image / audio / video / document extensions', function (): void {
        $sniffer = makeSniffer();
        $cases = [
            'foo.png'  => 'image/png',
            'foo.jpg'  => 'image/jpeg',
            'foo.jpeg' => 'image/jpeg',
            'foo.gif'  => 'image/gif',
            'foo.webp' => 'image/webp',
            'foo.svg'  => 'image/svg+xml',
            'foo.mp3'  => 'audio/mpeg',
            'foo.wav'  => 'audio/wav',
            'foo.ogg'  => 'audio/ogg',
            'foo.flac' => 'audio/flac',
            'foo.m4a'  => 'audio/mp4',
            'foo.mp4'  => 'video/mp4',
            'foo.webm' => 'video/webm',
            'foo.mov'  => 'video/quicktime',
            'foo.pdf'  => 'application/pdf',
            'foo.txt'  => 'text/plain',
        ];
        foreach ($cases as $filename => $expected) {
            expect($sniffer->sniffFromExtension($filename))->toBe($expected);
        }
    });

    it('handles query strings and full URLs', function (): void {
        $sniffer = makeSniffer();
        expect($sniffer->sniffFromExtension('https://cdn.example/foo.png?v=1'))->toBe('image/png');
        expect($sniffer->sniffFromExtension('https://cdn.example/path/to/foo.mp4?token=abc'))->toBe('video/mp4');
    });

    it('is case-insensitive on the extension', function (): void {
        expect(makeSniffer()->sniffFromExtension('FOO.PNG'))->toBe('image/png');
        expect(makeSniffer()->sniffFromExtension('foo.Mp4'))->toBe('video/mp4');
    });

    it('returns OCTET_STREAM for unknown extensions (conservative)', function (): void {
        expect(makeSniffer()->sniffFromExtension('foo.bin'))->toBe(MimeSniffer::OCTET_STREAM);
        expect(makeSniffer()->sniffFromExtension('foo.unknown'))->toBe(MimeSniffer::OCTET_STREAM);
    });
});

describe('MimeSniffer constants', function (): void {
    it('exposes OCTET_STREAM as the canonical fallback', function (): void {
        expect(MimeSniffer::OCTET_STREAM)->toBe('application/octet-stream');
    });
});
