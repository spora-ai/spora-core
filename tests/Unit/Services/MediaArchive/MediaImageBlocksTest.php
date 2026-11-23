<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaImageBlocks;

/**
 * A 1×1 PNG — the smallest thing that is really an image.
 */
function mediaImageBlockPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
}

/**
 * An asset whose bytes the archive holds inline.
 */
function makeDataUrlImageAsset(string $uuid, string $payload): MediaAsset
{
    return MediaAsset::create([
        'id'           => $uuid,
        'asset_url'    => "/api/v1/assets/{$uuid}.png",
        'storage_mode' => 'data_url',
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
        'byte_size'    => strlen($payload),
        'user_id'      => 1,
        'payload'      => $payload,
        'filename'     => 'pixel.png',
    ]);
}

/**
 * A storage directory of the asset's own, so a test can write the bytes the
 * archive would have written.
 */
function makeImageBlockStorageDir(): string
{
    $dir = sys_get_temp_dir() . '/spora-image-blocks-' . bin2hex(random_bytes(4));
    mkdir($dir . '/assets', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$dir}");
    $_ENV['SPORA_STORAGE_DIR']    = $dir;
    $_SERVER['SPORA_STORAGE_DIR'] = $dir;

    return $dir;
}

function removeImageBlockStorageDir(string $dir): void
{
    foreach (glob($dir . '/assets/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($dir . '/assets');
    @rmdir($dir);
}

/**
 * A local asset whose bytes are on disk under the name the archive writes.
 */
function makeLocalImageAsset(string $uuid, string $token, int $byteSize): MediaAsset
{
    return MediaAsset::create([
        'id'           => $uuid,
        'asset_url'    => "/api/v1/assets/{$uuid}.png",
        'storage_mode' => 'local',
        'asset_token'  => $token,
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
        'byte_size'    => $byteSize,
        'user_id'      => 1,
        'filename'     => 'pixel.png',
    ]);
}

describe('MediaImageBlocks', function () {
    it('renders an asset it can read into an image block', function (): void {
        $png = mediaImageBlockPng();
        $asset = makeDataUrlImageAsset('44444444-4444-4444-8444-444444444444', $png);

        $rendered = MediaImageBlocks::renderAsset($asset, BASE_PATH);

        expect($rendered)->not->toBeNull();
        expect($rendered['block']['type'])->toBe('image');
        expect($rendered['block']['mediaType'])->toBe('image/png');
        expect(base64_decode((string) $rendered['block']['base64'], strict: true))->toBe($png);
    });

    it('returns null for an asset whose bytes are not on hand', function (): void {
        // An `external` asset lives on someone else's server: the archive
        // holds a pointer, never the bytes.
        $asset = MediaAsset::create([
            'id'           => '55555555-5555-4555-8555-555555555555',
            'asset_url'    => 'https://cdn.example.com/55555555-5555-4555-8555-555555555555.png',
            'storage_mode' => 'external',
            'media_type'   => 'image',
            'mime_type'    => 'image/png',
            'byte_size'    => 1024,
            'user_id'      => 1,
            'filename'     => 'pixel.png',
        ]);

        expect(MediaImageBlocks::renderAsset($asset, BASE_PATH))->toBeNull();
    });

    it('returns null for a local asset whose file is gone', function (): void {
        $dir = makeImageBlockStorageDir();
        $asset = makeLocalImageAsset(
            '66666666-6666-4666-8666-666666666666',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            1024,
        );

        expect(MediaImageBlocks::renderAsset($asset, BASE_PATH))->toBeNull();

        removeImageBlockStorageDir($dir);
    });

    it('refuses an image over the per-asset cap', function (): void {
        // The cap is on the bytes that are loaded, so the body has to be
        // really there — a recorded size alone must not pass.
        $dir = makeImageBlockStorageDir();
        $paths = new Paths(BASE_PATH);
        $token = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $oversize = str_repeat('x', MediaImageBlocks::MAX_IMAGE_BYTES + 1);
        file_put_contents($paths->storage('assets') . '/' . $token . '.png', $oversize);

        $asset = makeLocalImageAsset(
            '77777777-7777-4777-8777-777777777777',
            $token,
            strlen($oversize),
        );

        expect(MediaImageBlocks::renderAsset($asset, BASE_PATH))->toBeNull();

        removeImageBlockStorageDir($dir);
    });

    it('keeps an image that is exactly at the per-asset cap', function (): void {
        $dir = makeImageBlockStorageDir();
        $paths = new Paths(BASE_PATH);
        $token = 'cccccccccccccccccccccccccccccccc';

        $atCap = str_repeat('x', MediaImageBlocks::MAX_IMAGE_BYTES);
        file_put_contents($paths->storage('assets') . '/' . $token . '.png', $atCap);

        $asset = makeLocalImageAsset(
            '88888888-8888-4888-8888-888888888888',
            $token,
            strlen($atCap),
        );

        expect(MediaImageBlocks::renderAsset($asset, BASE_PATH))->not->toBeNull();

        removeImageBlockStorageDir($dir);
    });

    it('returns null when the file cannot be read', function (): void {
        // A file that is there but unreadable: the read fails, and the
        // failure must read as "no bytes" rather than as a zero-byte
        // image that looks like a successful load.
        $dir = makeImageBlockStorageDir();
        $paths = new Paths(BASE_PATH);
        $token = 'dddddddddddddddddddddddddddddddd';

        $path = $paths->storage('assets') . '/' . $token . '.png';
        file_put_contents($path, mediaImageBlockPng());
        chmod($path, 0o000);

        $asset = makeLocalImageAsset(
            '99999999-9999-4999-8999-999999999999',
            $token,
            1024,
        );

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $bytes = MediaImageBlocks::loadBytes($asset, BASE_PATH);
            $rendered = MediaImageBlocks::renderAsset($asset, BASE_PATH);
        } finally {
            restore_error_handler();
        }

        expect($bytes)->toBeNull();
        expect($rendered)->toBeNull();

        chmod($path, 0o644);
        removeImageBlockStorageDir($dir);
    });
});
