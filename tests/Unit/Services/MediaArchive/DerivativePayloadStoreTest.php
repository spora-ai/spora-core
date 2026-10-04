<?php

declare(strict_types=1);

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativePayloadStore;

/**
 * `data_url` is the BLOB column; `local` is a file under
 * `storage/assets/<token>.<ext>`. The `local` layout has to match
 * `LocalAssetStore`'s, or a derivative written here cannot be read back
 * by the original's reader.
 */
function payloadStore(): DerivativePayloadStore
{
    return new DerivativePayloadStore(BASE_PATH);
}

function payloadAsset(string $mode, ?string $token = 'tok', ?string $mime = 'text/markdown'): MediaAsset
{
    $asset = new MediaAsset();
    $asset->storage_mode = $mode;
    $asset->asset_token  = $token;
    $asset->mime_type    = $mime;
    $asset->payload      = null;

    return $asset;
}

test('read() returns the BLOB payload in data_url mode', function (): void {
    $asset = payloadAsset('data_url');
    $asset->payload = "# Heading\n\nbody";

    expect(payloadStore()->read($asset))->toBe("# Heading\n\nbody");
});

test('read() returns empty when a data_url payload is not a string', function (): void {
    expect(payloadStore()->read(payloadAsset('data_url')))->toBe('');
});

test('rewrite() replaces the BLOB in place without changing the token', function (): void {
    $asset = payloadAsset('data_url', 'tok-stable');
    $asset->payload = 'v1';

    payloadStore()->rewrite($asset, 'v2');

    expect($asset->payload)->toBe('v2')
        ->and($asset->asset_token)->toBe('tok-stable');
});

test('remove() leaves a data_url row alone, the row delete drops the column', function (): void {
    $asset = payloadAsset('data_url');
    $asset->payload = 'still here';

    payloadStore()->remove($asset);

    expect($asset->payload)->toBe('still here');
});

test('read() and rewrite() round-trip bytes through a local file', function (): void {
    $token = 'payloadstore-' . bin2hex(random_bytes(4));
    $asset = payloadAsset('local', $token);
    $path  = payloadStore()->pathFor($asset);
    expect($path)->not->toBeNull();
    // `LocalAssetStore::store()` owns creating this directory; the rewrite
    // path only ever runs against a row that already has a file.
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    file_put_contents($path, 'v1');

    expect(payloadStore()->read($asset))->toBe('v1');

    payloadStore()->rewrite($asset, 'v2 longer');
    expect(file_get_contents($path))->toBe('v2 longer');

    payloadStore()->remove($asset);
    expect(file_exists($path))->toBeFalse();
});

test('pathFor() places a local asset under storage/assets with its extension', function (): void {
    $path = payloadStore()->pathFor(payloadAsset('local', 'tok-abc', 'text/markdown'));

    expect($path)->toEndWith('/storage/assets/tok-abc.md');
});

test('pathFor() returns null without a token, and pathFor() is local-mode only', function (): void {
    expect(payloadStore()->pathFor(payloadAsset('local', '')))->toBeNull()
        ->and(payloadStore()->pathFor(payloadAsset('local', null)))->toBeNull()
        ->and(payloadStore()->pathFor(payloadAsset('data_url', 'tok-abc')))->toBeNull();
});

test('read() returns empty for a local asset whose file is missing', function (): void {
    expect(payloadStore()->read(payloadAsset('local', 'never-written')))->toBe('');
});

test('rewrite() is a no-op for a local asset with no resolvable path', function (): void {
    $asset = payloadAsset('local', '');

    payloadStore()->rewrite($asset, 'v2');

    expect($asset->payload)->toBeNull();
});

test('remove() tolerates a missing file instead of warning', function (): void {
    payloadStore()->remove(payloadAsset('local', 'never-written'));

    expect(true)->toBeTrue();
});
