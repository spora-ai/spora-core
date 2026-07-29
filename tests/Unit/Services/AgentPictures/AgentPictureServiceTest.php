<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AgentPictures;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Models\MediaAsset;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentPictures\Archetype;
use Spora\Services\AgentPictures\Palette;

/**
 * Unit tests for the AgentPictureService — the single source of truth for
 * the picture CRUD/validation/wire-format contract.
 */
beforeEach(function (): void {
    $this->service = new AgentPictureService();
    $this->userId = bootAuth(bootAuthLayer());
    Capsule::table('agents')->insert([
        'id' => 1, 'user_id' => $this->userId, 'name' => 'Test', 'max_steps' => 10,
        'is_active' => 1, 'allow_followup' => 1, 'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
});

test('getOrCreate creates a default picture on first read', function (): void {
    $picture = $this->service->getOrCreate(1);

    expect($picture->agent_id)->toBe(1);
    expect($picture->archetype)->toBe(Archetype::Assistant->value);
    expect($picture->palette_key)->toBe(Palette::Slate->value);
    expect($picture->variant_key)->toBeNull();
    expect($picture->media_asset_id)->toBeNull();
});

test('getOrCreate returns the existing row on subsequent reads', function (): void {
    $first = $this->service->getOrCreate(1);
    $second = $this->service->getOrCreate(1);

    expect($second->id)->toBe($first->id);
});

test('updateAvatar sets archetype + palette + variant_key', function (): void {
    $picture = $this->service->updateAvatar(1, 'researcher', 'v2', 'violet');

    expect($picture->archetype)->toBe('researcher');
    expect($picture->variant_key)->toBe('v2');
    expect($picture->palette_key)->toBe('violet');
});

test('updateAvatar is partial — null fields are left untouched', function (): void {
    $this->service->updateAvatar(1, 'researcher', 'v1', 'violet');
    $picture = $this->service->updateAvatar(1, 'analyst', null, null);

    expect($picture->archetype)->toBe('analyst');
    expect($picture->variant_key)->toBe('v1');
    expect($picture->palette_key)->toBe('violet');
});

test('updateAvatar rejects unknown archetype', function (): void {
    $this->service->updateAvatar(1, 'astronaut', null, null);
})->throws(InvalidArgumentException::class, "Unknown archetype 'astronaut'");

test('updateAvatar rejects unknown palette', function (): void {
    $this->service->updateAvatar(1, 'researcher', null, 'rainbow');
})->throws(InvalidArgumentException::class, "Unknown palette_key 'rainbow'");

test('updateAvatar rejects unknown variant_key', function (): void {
    $this->service->updateAvatar(1, 'researcher', 'v9', null);
})->throws(InvalidArgumentException::class, "Unknown variant_key 'v9'");

test('updateAvatar clears any attached image', function (): void {
    $asset = seedMediaAsset();
    $this->service->attachImage(1, $asset);

    $picture = $this->service->updateAvatar(1, 'researcher', null, 'violet');

    expect($picture->media_asset_id)->toBeNull();
    expect($picture->archetype)->toBe('researcher');
});

test('attachImage swaps in the upload and clears the avatar fields', function (): void {
    $this->service->updateAvatar(1, 'researcher', 'v1', 'violet');

    $asset = seedMediaAsset();

    $picture = $this->service->attachImage(1, $asset);

    expect($picture->media_asset_id)->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    expect($picture->archetype)->toBeNull();
    expect($picture->variant_key)->toBeNull();
    expect($picture->palette_key)->toBeNull();
});

test('detachImage clears the image and falls back to defaults', function (): void {
    $asset = seedMediaAsset();
    $this->service->attachImage(1, $asset);

    $picture = $this->service->detachImage(1);

    expect($picture->media_asset_id)->toBeNull();
    expect($picture->archetype)->toBe(Archetype::Assistant->value);
    expect($picture->palette_key)->toBe(Palette::Slate->value);
});

test('toWireShape returns null for an agent with no picture', function (): void {
    $wire = $this->service->toWireShape(1);
    expect($wire)->toBeNull();
});

test('toWireShape returns the resolved avatar shape for an archetype picture', function (): void {
    $this->service->updateAvatar(1, 'researcher', 'v1', 'violet');

    $wire = $this->service->toWireShape(1);

    expect($wire)->toMatchArray([
        'kind' => 'avatar',
        'archetype' => 'researcher',
        'variant_key' => 'v1',
        'palette_key' => 'violet',
        'fg_color' => '#F5F3FF',
        'bg_color' => '#6D28D9',
        'image_url' => null,
        'image_updated_at' => null,
    ]);
});

test('toWireShape resolves variant_key from fnv1a(agent_id) when unset', function (): void {
    $this->service->updateAvatar(1, 'researcher', null, 'violet');

    $wire = $this->service->toWireShape(1);

    expect($wire['variant_key'])->toMatch('/^v[0-2]$/');
});

test('toWireShape returns the image shape for an uploaded picture', function (): void {
    $asset = seedMediaAsset();
    $this->service->attachImage(1, $asset);

    $wire = $this->service->toWireShape(1);

    expect($wire['kind'])->toBe('image');
    expect($wire['archetype'])->toBeNull();
    expect($wire['image_url'])->toBe('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png');
});

test('applyTemplateMetadata returns null when no picture fields are present', function (): void {
    $result = $this->service->applyTemplateMetadata(1, ['category' => 'general']);
    expect($result)->toBeNull();
});

test('applyTemplateMetadata applies archetype + variant + palette', function (): void {
    $picture = $this->service->applyTemplateMetadata(1, [
        'archetype' => 'researcher',
        'variant_key' => 'v2',
        'palette_key' => 'violet',
    ]);

    expect($picture)->not->toBeNull();
    expect($picture->archetype)->toBe('researcher');
    expect($picture->variant_key)->toBe('v2');
    expect($picture->palette_key)->toBe('violet');
});

test('applyTemplateMetadata throws on unknown archetype (caller should pre-validate)', function (): void {
    $this->service->applyTemplateMetadata(1, [
        'archetype' => 'astronaut',
        'palette_key' => 'violet',
    ]);
})->throws(InvalidArgumentException::class, "Unknown archetype 'astronaut'");

test('normaliseVariantKey accepts v0, v1, v2 only', function (): void {
    expect($this->service->normaliseVariantKey('v0'))->toBe('v0');
    expect($this->service->normaliseVariantKey('v1'))->toBe('v1');
    expect($this->service->normaliseVariantKey('v2'))->toBe('v2');
    $this->service->normaliseVariantKey('v3');
})->throws(InvalidArgumentException::class);

function seedMediaAsset(): MediaAsset
{
    $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    Capsule::table('media_assets')->insert([
        'id' => $id,
        'asset_url' => "/api/v1/assets/{$id}.png",
        'storage_mode' => 'local',
        'user_id' => null,
        'upload_source' => 'avatar',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    return MediaAsset::find($id);
}
