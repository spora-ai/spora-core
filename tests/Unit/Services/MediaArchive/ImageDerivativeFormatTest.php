<?php

declare(strict_types=1);

use Spora\Services\MediaArchive\ImageDerivativeFormat;

test('formatKeys returns the five preset identifiers in catalogue order', function (): void {
    expect(ImageDerivativeFormat::formatKeys())->toBe([
        'thumbnail-256',
        'medium-1024',
        'format-png',
        'format-jpeg',
        'format-webp',
    ]);
});

test('for returns the preset row for a known key', function (): void {
    $row = ImageDerivativeFormat::for('thumbnail-256');
    expect($row)->not->toBeNull();
    expect($row['format'])->toBe('thumbnail-256');
    expect($row['label'])->toBe('Thumbnail (256px)');
    expect($row['kind'])->toBe(ImageDerivativeFormat::KIND_RESIZE);
    expect($row['longEdge'])->toBe(256);
    expect($row['targetMime'])->toBe('image/webp');
    expect($row['quality'])->toBe(80);
});

test('for returns the convert-preset row including a null longEdge', function (): void {
    $row = ImageDerivativeFormat::for('format-png');
    expect($row['kind'])->toBe(ImageDerivativeFormat::KIND_CONVERT);
    expect($row['longEdge'])->toBeNull();
    expect($row['targetMime'])->toBe('image/png');
    expect($row['quality'])->toBeNull();
});

test('for returns null for an unknown key', function (): void {
    expect(ImageDerivativeFormat::for('garbage'))->toBeNull();
    expect(ImageDerivativeFormat::for('THUMBNAIL-256'))->toBeNull();
});

test('labelFor returns the human label for known keys and an upper-case slug otherwise', function (): void {
    expect(ImageDerivativeFormat::labelFor('thumbnail-256'))->toBe('Thumbnail (256px)');
    expect(ImageDerivativeFormat::labelFor('format-jpeg'))->toBe('Convert to JPEG');
    expect(ImageDerivativeFormat::labelFor('unknown-format'))->toBe('UNKNOWN-FORMAT');
});

test('every preset declares a targetMime that ImageDerivativeProducer::encodeTo handles', function (): void {
    $supported = ['image/png', 'image/jpeg', 'image/webp'];
    foreach (ImageDerivativeFormat::FORMAT_PRESETS as $row) {
        expect($row['targetMime'])->toBeIn($supported, sprintf(
            'preset "%s" targets unsupported MIME "%s"',
            $row['format'],
            $row['targetMime'],
        ));
    }
});

test('resize presets declare a positive longEdge', function (): void {
    foreach (ImageDerivativeFormat::FORMAT_PRESETS as $row) {
        if ($row['kind'] !== ImageDerivativeFormat::KIND_RESIZE) {
            continue;
        }
        expect($row['longEdge'])->toBeInt();
        expect($row['longEdge'])->toBeGreaterThan(0);
    }
});

test('convert presets declare a non-null quality except PNG (lossless)', function (): void {
    foreach (ImageDerivativeFormat::FORMAT_PRESETS as $row) {
        if ($row['kind'] !== ImageDerivativeFormat::KIND_CONVERT) {
            continue;
        }
        if ($row['targetMime'] === 'image/png') {
            continue;
        }
        expect($row['quality'])->toBeInt();
        expect($row['quality'])->toBeGreaterThan(0);
        expect($row['quality'])->toBeLessThanOrEqual(100);
    }
});
