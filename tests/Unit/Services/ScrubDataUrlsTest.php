<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Spora\Services\ScrubDataUrls;

test('scrub returns empty string for empty input', function (): void {
    expect(ScrubDataUrls::scrub(''))->toBe('');
});

test('scrub passes through text with no data URIs', function (): void {
    $text = 'A short response with no media references.';
    expect(ScrubDataUrls::scrub($text))->toBe($text);
});

test('scrub replaces a single base64 data URI with the placeholder', function (): void {
    $uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    $text = "Here is an image: {$uri}";

    $scrubbed = ScrubDataUrls::scrub($text);
    expect($scrubbed)->toContain(ScrubDataUrls::PLACEHOLDER);
    expect($scrubbed)->not->toContain('iVBORw0KGgo');
});

test('scrub replaces multiple base64 data URIs in the same string', function (): void {
    $uri1 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    $uri2 = 'data:audio/mpeg;base64,SUQzAwAAAAACc1RL';
    $text = "Image 1: {$uri1}; audio: {$uri2}";

    $scrubbed = ScrubDataUrls::scrub($text);
    // Two placeholders for two URIs.
    expect(substr_count($scrubbed, ScrubDataUrls::PLACEHOLDER))->toBe(2);
});

test('scrub leaves non-base64 data URIs alone', function (): void {
    $text = 'A non-base64 data URL: data:,Hello — should not be scrubbed.';
    expect(ScrubDataUrls::scrub($text))->toBe($text);
});

test('scrub handles oversized payloads (>1MB base64)', function (): void {
    $huge = 'data:image/png;base64,' . str_repeat('A', 2_000_000);
    $text = "prefix {$huge} suffix";

    $scrubbed = ScrubDataUrls::scrub($text);
    expect($scrubbed)->not->toContain(str_repeat('A', 100));
    expect(substr_count($scrubbed, ScrubDataUrls::PLACEHOLDER))->toBe(1);
});
