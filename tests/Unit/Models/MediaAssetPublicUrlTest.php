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

test('publicUrl() always starts with the opaque asset URL prefix', function (): void {
    $asset = new MediaAsset();
    $asset->id        = '11111111-2222-3333-4444-555555555555';
    $asset->mime_type = 'image/png';

    expect($asset->publicUrl())->toStartWith('/api/v1/assets/');
});

test('publicUrl() reflects the row id verbatim in the path', function (): void {
    $asset = new MediaAsset();
    $asset->id        = 'deadbeef-cafe-babe-1234-fedcba987654';
    $asset->mime_type = null;

    expect($asset->publicUrl())->toBe('/api/v1/assets/deadbeef-cafe-babe-1234-fedcba987654');
});

test('publicUrl() uppercase mime falls back to no extension', function (): void {
    $asset = new MediaAsset();
    $asset->id        = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $asset->mime_type = 'IMAGE/PNG-UPPERCASE'; // not in the canonical map

    expect($asset->publicUrl())->toBe('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
});

test('typedMediaType() returns Unknown for null', function (): void {
    $asset = new MediaAsset();
    $asset->media_type = null;

    expect($asset->typedMediaType())->toBe(Spora\Services\MediaArchive\MediaType::Unknown);
});

test('typedMediaType() returns Unknown for empty string', function (): void {
    $asset = new MediaAsset();
    $asset->media_type = '';

    expect($asset->typedMediaType())->toBe(Spora\Services\MediaArchive\MediaType::Unknown);
});

test('typedMediaType() returns Unknown for invalid enum value', function (): void {
    $asset = new MediaAsset();
    $asset->media_type = 'not-a-known-media-type';

    expect($asset->typedMediaType())->toBe(Spora\Services\MediaArchive\MediaType::Unknown);
});

test('typedMediaType() returns the matching enum for known values', function (): void {
    foreach (Spora\Services\MediaArchive\MediaType::cases() as $case) {
        $asset = new MediaAsset();
        $asset->media_type = $case->value;
        expect($asset->typedMediaType())->toBe($case);
    }
});

test('agent(), task(), and user() return the named BelongsTo relation', function (): void {
    $asset = new MediaAsset();

    $agent = $asset->agent();
    $task  = $asset->task();
    $user  = $asset->user();

    expect($agent)->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($task)->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($user)->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});
