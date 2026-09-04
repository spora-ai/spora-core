<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Services\MediaArchive\MediaArchiveService;

/**
 * Pins down {@see MediaArchiveService::extensionForMime()} and the
 * reverse {@see MediaArchiveService::mimeForExtension()} for every
 * round-trip the upload pipeline relies on. The static maps live
 * here because no plugin extension point exists — the round-trip
 * is what `MediaAllowedTypesService` and the upload UI use to know
 * "this extension is text, this MIME is its twin".
 */
describe('MediaArchiveService::extensionForMime', function (): void {
    it('maps every supported MIME to its canonical extension', function (): void {
        $cases = [
            'audio/mpeg'      => 'mp3',
            'audio/wav'       => 'wav',
            'audio/ogg'       => 'ogg',
            'audio/mp4'       => 'm4a',
            'audio/flac'      => 'flac',
            'video/mp4'       => 'mp4',
            'video/webm'      => 'webm',
            'video/quicktime' => 'mov',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'image/svg+xml'   => 'svg',
            'application/pdf' => 'pdf',
            'text/plain'      => 'txt',
            'text/x-typst'    => 'typ',
        ];
        foreach ($cases as $mime => $expected) {
            expect(MediaArchiveService::extensionForMime($mime))->toBe($expected);
        }
    });

    it('is case-insensitive on the MIME input', function (): void {
        expect(MediaArchiveService::extensionForMime('TEXT/X-TYPST'))->toBe('typ');
        expect(MediaArchiveService::extensionForMime('Application/PDF'))->toBe('pdf');
    });

    it('returns null for an empty or non-string MIME', function (): void {
        expect(MediaArchiveService::extensionForMime(''))->toBeNull();
        expect(MediaArchiveService::extensionForMime(null))->toBeNull();
    });

    it('returns null for an unrecognised MIME', function (): void {
        expect(MediaArchiveService::extensionForMime('application/x-foo'))->toBeNull();
        expect(MediaArchiveService::extensionForMime('text/x-unknown'))->toBeNull();
    });
});

describe('MediaArchiveService::mimeForExtension', function (): void {
    it('maps every supported extension to its canonical MIME', function (): void {
        $cases = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'typ' => 'text/x-typst',
        ];
        foreach ($cases as $ext => $expected) {
            expect(MediaArchiveService::mimeForExtension($ext))->toBe($expected);
        }
    });

    it('strips a leading dot and is case-insensitive', function (): void {
        expect(MediaArchiveService::mimeForExtension('.typ'))->toBe('text/x-typst');
        expect(MediaArchiveService::mimeForExtension('TYP'))->toBe('text/x-typst');
    });

    it('returns null for an empty or non-string extension', function (): void {
        expect(MediaArchiveService::mimeForExtension(''))->toBeNull();
        expect(MediaArchiveService::mimeForExtension(null))->toBeNull();
    });

    it('returns null for an unrecognised extension', function (): void {
        expect(MediaArchiveService::mimeForExtension('bin'))->toBeNull();
        expect(MediaArchiveService::mimeForExtension('unknown'))->toBeNull();
    });
});
