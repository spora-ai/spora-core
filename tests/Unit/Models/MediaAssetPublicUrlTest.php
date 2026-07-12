<?php

declare(strict_types=1);

use Spora\Models\MediaAsset;

/**
 * Cover {@see MediaAsset::publicUrl()} — the canonical URL accessor
 * that backs the media endpoint's `asset_url` field. The URL must always
 * carry the file extension (`.png`, `.jpg`, `.mp3`, …) derived from
 * the row's sniffed mime so browsers download with the right filename.
 */
test('publicUrl() appends the extension when the sniffed mime is known', function (): void {
    $asset = new MediaAsset();
    $asset->id        = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $asset->mime_type = 'image/png';

    expect($asset->publicUrl())->toBe('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png');
});

test('publicUrl() returns the bare UUID when the mime is null', function (): void {
    $asset = new MediaAsset();
    $asset->id        = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $asset->mime_type = null;

    expect($asset->publicUrl())->toBe('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
});

test('publicUrl() returns the bare UUID when the mime is unknown', function (): void {
    $asset = new MediaAsset();
    $asset->id        = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $asset->mime_type = 'application/x-totally-made-up';

    expect($asset->publicUrl())->toBe('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
});

test('publicUrl() covers the canonical mime→ext map', function (): void {
    $cases = [
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
        'image/gif'        => 'gif',
        'image/webp'       => 'webp',
        'image/svg+xml'    => 'svg',
        'audio/mpeg'       => 'mp3',
        'audio/wav'        => 'wav',
        'audio/ogg'        => 'ogg',
        'audio/mp4'        => 'm4a',
        'audio/flac'       => 'flac',
        'video/mp4'        => 'mp4',
        'video/webm'       => 'webm',
        'video/quicktime'  => 'mov',
        'application/pdf'  => 'pdf',
        'text/plain'       => 'txt',
    ];

    foreach ($cases as $mime => $ext) {
        $asset = new MediaAsset();
        $asset->id        = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $asset->mime_type = $mime;

        expect($asset->publicUrl())->toBe("/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.{$ext}")
            ->and($asset->publicUrl())->toEndWith(".{$ext}");
    }
});
