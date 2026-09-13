<?php

declare(strict_types=1);

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaAssetSerializer;

test('serialize emits is_temporary=false on a fresh non-temp asset', function (): void {
    $serializer = new MediaAssetSerializer();
    $asset = new MediaAsset();
    $asset->id = bin2hex(random_bytes(8));
    $asset->user_id = 1;
    $asset->asset_url = '/api/v1/assets/' . $asset->id;
    $asset->storage_mode = 'local';
    $asset->save();

    $payload = $serializer->serialize($asset);
    expect(array_key_exists('is_temporary', $payload))->toBeTrue();
    expect($payload['is_temporary'])->toBeFalse();
});

test('serialize emits is_temporary=true when the row is stamped temp', function (): void {
    $serializer = new MediaAssetSerializer();
    $asset = new MediaAsset();
    $asset->id = bin2hex(random_bytes(8));
    $asset->user_id = 1;
    $asset->asset_url = '/api/v1/assets/' . $asset->id;
    $asset->storage_mode = 'local';
    $asset->is_temporary = true;
    $asset->save();

    $payload = $serializer->serialize($asset);
    expect($payload['is_temporary'])->toBeTrue();
});

test('serialize coerces null and missing to false (no leftover nulls on the wire)', function (): void {
    $serializer = new MediaAssetSerializer();
    $asset = new MediaAsset();
    $asset->id = bin2hex(random_bytes(8));
    $asset->user_id = 1;
    $asset->asset_url = '/api/v1/assets/' . $asset->id;
    $asset->storage_mode = 'local';
    // Default value comes from the migration's `DEFAULT FALSE`, so a
    // freshly-persisted row reads back as `false` — the contract
    // guarantees no `null` leaks to the dashboard.
    $asset->save();

    $payload = $serializer->serialize($asset);
    expect($payload['is_temporary'])->toBeBool();
    expect($payload['is_temporary'])->toBeFalse();
});
