<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ProfilePictures;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\GroupMembership;
use Spora\Models\MediaAsset;
use Spora\Services\AgentPictures\Archetype;
use Spora\Services\AgentPictures\Palette;
use Spora\Services\Exceptions\AgentPictureNotOwnedException;
use Spora\Services\ProfilePictures\GroupPictureService;

/**
 * Coverage for {@see GroupPictureService} — the group-side CRUD surface
 * that reuses the validation, hashing, and wire-format logic from
 * {@see \Spora\Services\ProfilePictures\ProfilePictureService}.
 */
beforeEach(function (): void {
    $this->service = new GroupPictureService();
    $this->userId = bootAuth(bootAuthLayer());

    $this->groupId = Capsule::table('groups')->insertGetId([
        'name' => 'Test Group',
        'description' => null,
        'created_by_user_id' => $this->userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id' => $this->groupId,
        'user_id' => $this->userId,
        'role' => GroupMembership::ROLE_OWNER,
        'joined_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
});

test('getOrCreate creates a default picture on first read', function (): void {
    $picture = $this->service->getOrCreate($this->groupId);

    expect($picture->group_id)->toBe($this->groupId);
    expect($picture->archetype)->toBe(Archetype::Collaborative->value);
    expect($picture->palette_key)->toBe(Palette::Slate->value);
    expect($picture->variant_key)->toBeNull();
    expect($picture->media_asset_id)->toBeNull();
});

test('getOrCreate returns the existing row on subsequent reads', function (): void {
    $first = $this->service->getOrCreate($this->groupId);
    $second = $this->service->getOrCreate($this->groupId);

    expect($second->id)->toBe($first->id);
});

test('updateAvatar is partial — null fields are left untouched', function (): void {
    $this->service->updateAvatar($this->groupId, 'researcher', 'v1', 'violet');
    $picture = $this->service->updateAvatar($this->groupId, 'ensemble', null, null);

    expect($picture->archetype)->toBe('ensemble');
    expect($picture->variant_key)->toBe('v1');
    expect($picture->palette_key)->toBe('violet');
});

test('updateAvatar clears any attached image', function (): void {
    $asset = seedGroupMediaAsset($this->userId);
    $this->service->attachImage($this->groupId, $asset, $this->userId);

    $picture = $this->service->updateAvatar($this->groupId, 'researcher', null, 'violet');

    expect($picture->media_asset_id)->toBeNull();
    expect($picture->archetype)->toBe('researcher');
});

test('attachImage rejects assets owned by a different user', function (): void {
    $asset = seedGroupMediaAsset($this->userId + 1);

    $this->service->attachImage($this->groupId, $asset, $this->userId);
})->throws(AgentPictureNotOwnedException::class);

test('attachImage rejects assets with no owner (legacy rows)', function (): void {
    $asset = seedGroupMediaAsset(null);

    $this->service->attachImage($this->groupId, $asset, $this->userId);
})->throws(AgentPictureNotOwnedException::class);

test('attachImage swaps in the upload and preserves the avatar fields', function (): void {
    $this->service->updateAvatar($this->groupId, 'researcher', 'v1', 'violet');

    $asset = seedGroupMediaAsset($this->userId);

    $picture = $this->service->attachImage($this->groupId, $asset, $this->userId);

    expect($picture->media_asset_id)->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    // avatar fields preserved so detach restores the operator's previous choice
    expect($picture->archetype)->toBe('researcher');
    expect($picture->variant_key)->toBe('v1');
    expect($picture->palette_key)->toBe('violet');
});

test('detachImage clears the image and preserves the previous avatar', function (): void {
    $this->service->updateAvatar($this->groupId, 'researcher', 'v1', 'violet');
    $asset = seedGroupMediaAsset($this->userId);
    $this->service->attachImage($this->groupId, $asset, $this->userId);

    $picture = $this->service->detachImage($this->groupId);

    expect($picture->media_asset_id)->toBeNull();
    expect($picture->archetype)->toBe('researcher');
    expect($picture->variant_key)->toBe('v1');
    expect($picture->palette_key)->toBe('violet');
});

test('detachImage falls back to defaults when there was no previous avatar', function (): void {
    $asset = seedGroupMediaAsset($this->userId);
    $this->service->attachImage($this->groupId, $asset, $this->userId);

    $picture = $this->service->detachImage($this->groupId);

    expect($picture->media_asset_id)->toBeNull();
    expect($picture->archetype)->toBe(Archetype::Collaborative->value);
    expect($picture->palette_key)->toBe(Palette::Slate->value);
});

test('toWireShape returns the default avatar shape for a group with no picture', function (): void {
    $wire = $this->service->toWireShape($this->groupId);

    expect($wire['kind'])->toBe('avatar');
    expect($wire['archetype'])->toBe(Archetype::Collaborative->value);
    expect($wire['palette_key'])->toBe(Palette::Slate->value);
    expect($wire['variant_key'])->toMatch('/^v[0-2]$/');
});

test('toWireShape returns the resolved avatar shape for an archetype picture', function (): void {
    $this->service->updateAvatar($this->groupId, 'researcher', 'v1', 'violet');

    $wire = $this->service->toWireShape($this->groupId);

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

test('toWireShape returns the image shape for an uploaded picture', function (): void {
    $asset = seedGroupMediaAsset($this->userId);
    $this->service->attachImage($this->groupId, $asset, $this->userId);

    $wire = $this->service->toWireShape($this->groupId);

    expect($wire['kind'])->toBe('image');
    expect($wire['archetype'])->toBeNull();
    expect($wire['image_url'])->toBe('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png');
});

test('GroupPicture model returns the parent group', function (): void {
    $picture = $this->service->getOrCreate($this->groupId);

    expect((int) $picture->group->id)->toBe($this->groupId);
});

function seedGroupMediaAsset(?int $userId): MediaAsset
{
    $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    Capsule::table('media_assets')->insert([
        'id' => $id,
        'asset_url' => "/api/v1/assets/{$id}.png",
        'storage_mode' => 'local',
        'user_id' => $userId,
        'upload_source' => 'group_avatar',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    return MediaAsset::find($id);
}
