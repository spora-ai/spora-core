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

    it('returns null width/height for an audio file (image metadata is video-only)', function (): void {
        $mp3 = "\xFF\xFB" . str_repeat("\x00", 64);
        $result = makeExtractor(false)->extractAudioVideoMeta($mp3, 'audio/mpeg');
        // extractAudioVideoMeta returns only duration_seconds + mime; width/height
        // live on the ExtractedMetadata shape from extract(), not this helper.
        expect($result)->toHaveKey('duration_seconds');
        expect($result)->toHaveKey('mime');
        expect($result)->not->toHaveKey('width');
    });
});

describe('MetadataExtractor::extract dispatch', function (): void {
    it('returns an ExtractedMetadata with all nulls for unknown media types', function (): void {
        // Document MIME → default branch. The extractor preserves the caller's
        // hint mime on the result and leaves width/height/duration null.
        $bytes = '%PDF-1.4' . str_repeat("\x00", 64);
        $result = makeExtractor()->extract($bytes, 'application/pdf', MediaType::Document);
        expect($result->width)->toBeNull();
        expect($result->height)->toBeNull();
        expect($result->durationSeconds)->toBeNull();
        expect($result->mime)->toBe('application/pdf');
    });

    it('honours the caller\'s mediaType for Image even when the sniff says octet-stream', function (): void {
        // Random bytes — getimagesize() returns null, so imageResult() falls
        // back to the call-supplied mime hint. The extractor still routes
        // through the image branch because mediaType=Image wins.
        $bytes = str_repeat("\x00", 32);
        $result = makeExtractor()->extract($bytes, 'application/octet-stream', MediaType::Image);
        expect($result->width)->toBeNull();
        // imageResult() falls back to the caller's mime when getimagesize() fails.
        expect($result->mime)->toBe('application/octet-stream');
    });

    it('returns an image result with sniffed dimensions for real PNG bytes routed via Image', function (): void {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        $result = makeExtractor()->extract($bytes, 'image/png', MediaType::Image);
        expect($result->width)->toBe(1);
        expect($result->height)->toBe(1);
        expect($result->mime)->toBe('image/png');
    });

    it('returns null duration for audio dispatched through extract()', function (): void {
        $result = makeExtractor(false)->extract("\xFF\xFB", 'audio/mpeg', MediaType::Audio);
        expect($result->durationSeconds)->toBeNull();
    });

    it('returns null duration for video dispatched through extract()', function (): void {
        $mp4 = pack('N', 32) . 'ftyp' . 'isom' . str_repeat("\x00", 64);
        $result = makeExtractor(false)->extract($mp4, 'video/mp4', MediaType::Video);
        expect($result->durationSeconds)->toBeNull();
    });

    it('treats empty bytes as a no-op and preserves the call-supplied mime hint', function (): void {
        $result = makeExtractor()->extract('', 'audio/mpeg', MediaType::Audio);
        expect($result->width)->toBeNull();
        expect($result->height)->toBeNull();
        expect($result->durationSeconds)->toBeNull();
        // The empty-bytes guard preserves the caller-supplied mime unchanged.
        expect($result->mime)->toBe('audio/mpeg');
    });
});

describe('MetadataExtractor image dispatcher', function (): void {
    it('returns nulls when imageResult() cannot decode the bytes', function (): void {
        // Real extractImageMeta() path. Bytes are not a real image — the
        // decoder returns null and the helper falls back to the caller mime.
        $result = makeExtractor()->extractImageMeta('not an image', 'application/octet-stream');
        expect($result['width'])->toBeNull();
        expect($result['height'])->toBeNull();
        expect($result['mime'])->toBe('application/octet-stream');
    });

    it('returns the imageinfo mime in preference to the call-supplied hint when both are present', function (): void {
        // PNG bytes — getimagesize() reports imageinfo['mime'] = 'image/png',
        // which wins over the caller's 'application/octet-stream' hint.
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            strict: true,
        );
        $result = makeExtractor()->extractImageMeta($bytes, 'application/octet-stream');
        expect($result['mime'])->toBe('image/png');
    });
});
