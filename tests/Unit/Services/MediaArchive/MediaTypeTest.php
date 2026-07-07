<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Services\MediaArchive\MediaType;

/**
 * Direct unit coverage for the {@see MediaType} enum's `fromMime()`
 * factory. The mapping is the contract every other service file relies
 * on, so each primary MIME branch is pinned here.
 */
describe('MediaType::fromMime', function (): void {
    it('returns Unknown for null', function (): void {
        expect(MediaType::fromMime(null))->toBe(MediaType::Unknown);
    });

    it('returns Unknown for an empty string', function (): void {
        expect(MediaType::fromMime(''))->toBe(MediaType::Unknown);
    });

    it('maps image/* primary types to Image', function (): void {
        expect(MediaType::fromMime('image/png'))->toBe(MediaType::Image);
        expect(MediaType::fromMime('image/jpeg'))->toBe(MediaType::Image);
        expect(MediaType::fromMime('image/svg+xml'))->toBe(MediaType::Image);
        expect(MediaType::fromMime('image/webp'))->toBe(MediaType::Image);
    });

    it('maps audio/* primary types to Audio', function (): void {
        expect(MediaType::fromMime('audio/mpeg'))->toBe(MediaType::Audio);
        expect(MediaType::fromMime('audio/ogg'))->toBe(MediaType::Audio);
        expect(MediaType::fromMime('audio/flac'))->toBe(MediaType::Audio);
    });

    it('maps video/* primary types to Video', function (): void {
        expect(MediaType::fromMime('video/mp4'))->toBe(MediaType::Video);
        expect(MediaType::fromMime('video/webm'))->toBe(MediaType::Video);
        expect(MediaType::fromMime('video/quicktime'))->toBe(MediaType::Video);
    });

    it('maps application/* primary types to Document', function (): void {
        expect(MediaType::fromMime('application/pdf'))->toBe(MediaType::Document);
        expect(MediaType::fromMime('application/json'))->toBe(MediaType::Document);
        expect(MediaType::fromMime('application/zip'))->toBe(MediaType::Document);
    });

    it('maps text/* primary types to Document', function (): void {
        expect(MediaType::fromMime('text/plain'))->toBe(MediaType::Document);
        expect(MediaType::fromMime('text/html'))->toBe(MediaType::Document);
        expect(MediaType::fromMime('text/csv'))->toBe(MediaType::Document);
    });

    it('maps unrecognised primary types to Unknown', function (): void {
        expect(MediaType::fromMime('font/woff2'))->toBe(MediaType::Unknown);
        expect(MediaType::fromMime('chemical/x-mdl-sdfile'))->toBe(MediaType::Unknown);
    });

    it('is case-insensitive on the primary type segment', function (): void {
        expect(MediaType::fromMime('IMAGE/PNG'))->toBe(MediaType::Image);
        expect(MediaType::fromMime('Audio/MP3'))->toBe(MediaType::Audio);
    });
});

describe('MediaType enum values', function (): void {
    it('uses lowercase wire-format string values for serialization', function (): void {
        // The DB column is a varchar; using mixed-case here would force every
        // caller to normalise before comparing.
        expect(MediaType::Image->value)->toBe('image');
        expect(MediaType::Audio->value)->toBe('audio');
        expect(MediaType::Video->value)->toBe('video');
        expect(MediaType::Document->value)->toBe('document');
        expect(MediaType::Unknown->value)->toBe('unknown');
    });

    it('round-trips through tryFrom() for every case', function (): void {
        foreach (MediaType::cases() as $case) {
            expect(MediaType::tryFrom($case->value))->toBe($case);
        }
    });

    it('returns null for an invalid wire value', function (): void {
        expect(MediaType::tryFrom('bogus'))->toBeNull();
    });
});
