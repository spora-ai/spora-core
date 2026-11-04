<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Services\MediaArchive\MediaScriptableTypes;

/**
 * The serving-boundary vocabulary.
 *
 * These assertions are the rule the rest of the archive relies on: a
 * script-capable type is recognised, and the headers that neutralise it are
 * per-type rather than universal.
 */
test('text/html and image/svg+xml are script-capable', function (): void {
    expect(MediaScriptableTypes::isScriptable('text/html'))->toBeTrue();
    expect(MediaScriptableTypes::isScriptable('image/svg+xml'))->toBeTrue();
});

test('raster and document formats are not script-capable', function (): void {
    expect(MediaScriptableTypes::isScriptable('image/png'))->toBeFalse();
    expect(MediaScriptableTypes::isScriptable('image/jpeg'))->toBeFalse();
    expect(MediaScriptableTypes::isScriptable('application/pdf'))->toBeFalse();
    expect(MediaScriptableTypes::isScriptable('text/plain'))->toBeFalse();
    expect(MediaScriptableTypes::isScriptable('text/markdown'))->toBeFalse();
});

test('the comparison ignores case and charset parameters', function (): void {
    expect(MediaScriptableTypes::isScriptable('TEXT/HTML'))->toBeTrue();
    expect(MediaScriptableTypes::isScriptable('text/html; charset=utf-8'))->toBeTrue();
    expect(MediaScriptableTypes::isScriptable('  Image/SVG+XML  '))->toBeTrue();
});

test('a null or empty mime is not script-capable', function (): void {
    expect(MediaScriptableTypes::isScriptable(null))->toBeFalse();
    expect(MediaScriptableTypes::isScriptable(''))->toBeFalse();
});

test('every media response carries nosniff', function (): void {
    $headers = MediaScriptableTypes::headersFor('image/png');

    expect($headers)->toHaveKey('X-Content-Type-Options');
    expect($headers['X-Content-Type-Options'])->toBe('nosniff');
});

test('a script-capable response additionally carries a sandbox policy', function (): void {
    $headers = MediaScriptableTypes::headersFor('text/html');

    expect($headers['X-Content-Type-Options'])->toBe('nosniff');
    expect($headers)->toHaveKey('Content-Security-Policy');
    expect($headers['Content-Security-Policy'])->toBe('sandbox');
});

test('a non-script-capable response carries no sandbox policy', function (): void {
    $headers = MediaScriptableTypes::headersFor('image/png');

    expect($headers)->not->toHaveKey('Content-Security-Policy');
});

test('the embed policy sandboxes without allowing same-origin', function (): void {
    $policy = MediaScriptableTypes::embedPolicy(true);

    expect($policy)->toContain('sandbox');
    expect($policy)->toContain('allow-scripts');
    expect($policy)->not->toContain('allow-same-origin');
    expect($policy)->toContain("frame-ancestors 'self'");
});

test('the embed policy withholds scripts when they are not allowed', function (): void {
    $policy = MediaScriptableTypes::embedPolicy(false);

    expect($policy)->toContain('sandbox');
    expect($policy)->not->toContain('allow-scripts');
    expect($policy)->not->toContain('allow-same-origin');
});
