<?php

declare(strict_types=1);

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ImageDerivativeFormat;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\Producers\ImageDerivativeProducer;

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * Render an in-memory PNG of `$width × $height` pixels. Uses GD which
 * is a hard requirement of PHP — Imagick isn't needed to seed the
 * fixture.
 */
function makePng(int $width, int $height, int $red = 200, int $green = 100, int $blue = 50): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, $red, $green, $blue));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    return $bytes;
}

function seedImageAsset(string $bytes, string $mime, string $storageMode = 'data_url', ?string $assetToken = null): MediaAsset
{
    $id = sprintf(
        '%08x-aaaa-bbbb-cccc-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffffffffffff),
    );
    return MediaAsset::create([
        'id'                            => $id,
        'asset_url'                     => '/api/v1/assets/' . $id,
        'storage_mode'                  => $storageMode,
        'media_type'                    => 'image',
        'mime_type'                     => $mime,
        'byte_size'                     => strlen($bytes),
        'payload'                       => $storageMode === 'data_url' ? $bytes : null,
        'asset_token'                   => $assetToken ?? bin2hex(random_bytes(16)),
        'migrated_from_inline_data_url' => $storageMode === 'data_url',
    ]);
}

test('pluginSlug returns spora-core and operationName returns image.derive', function (): void {
    $producer = new ImageDerivativeProducer();
    expect($producer->pluginSlug())->toBe('spora-core');
    expect($producer->operationName())->toBe('image.derive');
});

test('supportedDerivativeFormats lists exactly the ImageDerivativeFormat catalogue', function (): void {
    $producer = new ImageDerivativeProducer();
    expect($producer->supportedDerivativeFormats())->toBe(ImageDerivativeFormat::formatKeys());
});

test('supportedSourceFormats covers the four raster MIMEs the operator can upload', function (): void {
    $producer = new ImageDerivativeProducer();
    expect($producer->supportedSourceFormats())->toBe([
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ]);
});

test('thumbnail-256 rescales a 512×512 source to 256×256 WebP', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset(makePng(512, 512), 'image/png');

    $out = $producer->produce($source, 'thumbnail-256');

    expect($out->mime)->toBe('image/webp');
    expect($out->width)->toBe(256);
    expect($out->height)->toBe(256);
    expect($out->durationSeconds)->toBeNull();
    expect($out->bytes)->not->toBe('');
    // RIFF magic bytes for WebP.
    expect(substr($out->bytes, 0, 4))->toBe('RIFF');
});

test('medium-1024 never upscales — a 512×512 source stays 512×512', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset(makePng(512, 512), 'image/png');

    $out = $producer->produce($source, 'medium-1024');

    expect($out->mime)->toBe('image/webp');
    expect($out->width)->toBe(512);
    expect($out->height)->toBe(512);
});

test('format-png re-encodes without resizing', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset(makePng(800, 600), 'image/jpeg');

    $out = $producer->produce($source, 'format-png');

    expect($out->mime)->toBe('image/png');
    expect($out->width)->toBe(800);
    expect($out->height)->toBe(600);
});

test('format-jpeg re-encodes as JPEG with the source dimensions', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset(makePng(640, 480), 'image/png');

    $out = $producer->produce($source, 'format-jpeg');

    expect($out->mime)->toBe('image/jpeg');
    expect($out->width)->toBe(640);
    expect($out->height)->toBe(480);
});

test('format-webp re-encodes as WebP with the source dimensions', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset(makePng(320, 200), 'image/png');

    $out = $producer->produce($source, 'format-webp');

    expect($out->mime)->toBe('image/webp');
    expect($out->width)->toBe(320);
    expect($out->height)->toBe(200);
    expect(substr($out->bytes, 0, 4))->toBe('RIFF');
});

test('produce throws InvalidArgumentException for an unknown format key', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset(makePng(64, 64), 'image/png');

    expect(fn() => $producer->produce($source, 'avatar'))
        ->toThrow(InvalidArgumentException::class, 'unknown format "avatar"');
});

test('produce throws InvalidArgumentException for an unsupported source MIME', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset('plain text', 'text/plain');

    expect(fn() => $producer->produce($source, 'thumbnail-256'))
        ->toThrow(InvalidArgumentException::class, 'source MIME "text/plain" is not supported');
});

test('produce throws when the data_url payload column is empty', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset('', 'image/png', 'data_url');
    // seedImageAsset refused empty bytes; force-create one with payload='':
    $source->payload = '';
    $source->save();

    expect(fn() => $producer->produce($source, 'thumbnail-256'))
        ->toThrow(RuntimeException::class, 'empty data_url payload');
});

test('produce reads local-mode bytes from <storage>/assets/<token>.<ext>', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-img-prod-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/assets', 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $bytes = makePng(512, 512);
    $token = bin2hex(random_bytes(16));
    file_put_contents("{$tmp}/assets/{$token}.png", $bytes);

    $source = seedImageAsset($bytes, 'image/png', 'local', $token);

    $producer = new ImageDerivativeProducer();
    $out = $producer->produce($source, 'format-webp');

    expect($out->mime)->toBe('image/webp');
    expect($out->width)->toBe(512);
    expect($out->height)->toBe(512);

    @unlink("{$tmp}/assets/{$token}.png");
    @rmdir("{$tmp}/assets");
    @rmdir($tmp);
});

test('produce throws when local-mode bytes are missing from disk', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-img-prod-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $source = seedImageAsset(makePng(64, 64), 'image/png', 'local', bin2hex(random_bytes(16)));
    // File is NOT written to disk — expect a clean error.

    $producer = new ImageDerivativeProducer();
    expect(fn() => $producer->produce($source, 'thumbnail-256'))
        ->toThrow(RuntimeException::class, 'local file unreadable');

    @rmdir($tmp);
});

test('produce throws on external storage_mode (no materialised bytes)', function (): void {
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset('irrelevant', 'image/png', 'external');

    expect(fn() => $producer->produce($source, 'thumbnail-256'))
        ->toThrow(RuntimeException::class, 'storage_mode "external" has no materialised bytes');
});

test('encoded bytes never carry the source dimensions for a different preset', function (): void {
    // Round-trip a 256×200 source through format-png; the resulting PNG
    // must be 256×200, not the 512×512 fixture used in other tests.
    $producer = new ImageDerivativeProducer();
    $source = seedImageAsset(makePng(256, 200), 'image/png');

    $out = $producer->produce($source, 'format-png');

    expect($out->width)->toBe(256);
    expect($out->height)->toBe(200);
});
