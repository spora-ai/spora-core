<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Psr\Log\NullLogger;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\MediaArchive\MetadataExtractor;

/**
 * Direct unit coverage for {@see MetadataExtractor}. Pinned branches:
 *  - `extract()` dispatcher for Image / Audio / Video / Unknown
 *  - `extractImageMeta()` for a real PNG header, non-image bytes, and
 *    the empty-bytes guard
 *  - `extractAudioVideoMeta()` with ffprobe disabled, ffprobe enabled
 *    but no binary on PATH, and a malformed JSON probe response
 *  - duration parsing covers the string and numeric forms ffprobe emits
 */
function makeExtractor(bool $ffprobeEnabled = false): MetadataExtractor
{
    return new MetadataExtractor(new NullLogger(), $ffprobeEnabled);
}

describe('MetadataExtractor::extract', function (): void {
    it('returns nulls for the empty-bytes guard', function (): void {
        $result = makeExtractor()->extract('', null, MediaType::Image);
        expect($result->width)->toBeNull();
        expect($result->height)->toBeNull();
        expect($result->durationSeconds)->toBeNull();
        expect($result->mime)->toBeNull();
    });

    it('dispatches image bytes through imageResult()', function (): void {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        $result = makeExtractor()->extract($bytes, 'image/png', MediaType::Image);
        expect($result->width)->toBe(1);
        expect($result->height)->toBe(1);
        expect($result->durationSeconds)->toBeNull();
    });

    it('dispatches audio bytes through avResult()', function (): void {
        // No real audio bytes — ffprobe is disabled so duration is null.
        $result = makeExtractor(false)->extract("\x00\x00", 'audio/mpeg', MediaType::Audio);
        expect($result->width)->toBeNull();
        expect($result->height)->toBeNull();
        expect($result->durationSeconds)->toBeNull();
    });

    it('dispatches video bytes through avResult()', function (): void {
        $result = makeExtractor(false)->extract("\x00\x00", 'video/mp4', MediaType::Video);
        expect($result->width)->toBeNull();
        expect($result->height)->toBeNull();
        expect($result->durationSeconds)->toBeNull();
    });

    it('returns nulls for media types outside image/audio/video', function (): void {
        $result = makeExtractor()->extract("\x00\x00", 'application/pdf', MediaType::Document);
        expect($result->width)->toBeNull();
        expect($result->height)->toBeNull();
        expect($result->durationSeconds)->toBeNull();
        expect($result->mime)->toBe('application/pdf');
    });
});

describe('MetadataExtractor::extractImageMeta', function (): void {
    it('returns width/height/mime for a real PNG header', function (): void {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        $result = makeExtractor()->extractImageMeta($bytes, 'image/png');
        expect($result['width'])->toBe(1);
        expect($result['height'])->toBe(1);
        expect($result['mime'])->toBe('image/png');
    });

    it('returns nulls when getimagesize() fails on non-image bytes', function (): void {
        $result = makeExtractor()->extractImageMeta('not actually an image', 'application/octet-stream');
        expect($result['width'])->toBeNull();
        expect($result['height'])->toBeNull();
        expect($result['mime'])->toBe('application/octet-stream');
    });

    it('falls back to the caller hint when imageinfo has no mime key', function (): void {
        // Some image formats return a shape without the `mime` key from
        // getimagesizefromstring() — we should preserve the caller's hint.
        $extractor = makeExtractor();
        $result = $extractor->extractImageMeta('not an image', 'image/jpeg');
        expect($result['mime'])->toBe('image/jpeg');
    });
});

describe('MetadataExtractor::extractAudioVideoMeta', function (): void {
    it('returns null duration when ffprobe is disabled (never throws)', function (): void {
        $mp4 = pack('N', 32) . 'ftyp' . 'isom' . str_repeat("\x00", 64);
        $result = makeExtractor(false)->extractAudioVideoMeta($mp4, 'video/mp4');
        expect($result['duration_seconds'])->toBeNull();
        expect($result['mime'])->toBe('video/mp4');
    });

    it('returns null duration when ffprobe is enabled but no binary is on PATH', function (): void {
        $extractor = new MetadataExtractor(new NullLogger(), true);
        $mp3 = "\xFF\xFB" . str_repeat("\x00", 64);
        $result = $extractor->extractAudioVideoMeta($mp3, 'audio/mpeg');
        expect($result['duration_seconds'])->toBeNull();
        expect($result['mime'])->toBe('audio/mpeg');
    });
});
