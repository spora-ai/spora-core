<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ProfilePictures;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use ReflectionClass;
use Spora\Models\GroupMembership;
use Spora\Models\MediaAsset;
use Spora\Services\AgentPictures\Archetype;
use Spora\Services\AgentPictures\Palette;
use Spora\Services\ProfilePictures\GroupPictureService;
use Spora\Services\ProfilePictures\ProfilePictureService;

/**
 * Coverage for the cross-subject surface of {@see ProfilePictureService}
 * — validation, hashing, wire-shape — that both the agent and group
 * pipelines inherit. Subject-specific behaviour (agent-side
 * `attachImage(Agent, MediaAsset)`, the group default archetype, …)
 * stays in the respective subclass tests.
 */
beforeEach(function (): void {
    // The base class is abstract; GroupPictureService is the cheapest
    // concrete subject for the cross-subject tests since it doesn't
    // require an Agent + LLMDriverConfiguration to construct.
    $this->service = new GroupPictureService();
    $this->userId = bootAuth(bootAuthLayer());

    $this->groupId = \Illuminate\Database\Capsule\Manager::table('groups')->insertGetId([
        'name' => 'Test Group',
        'description' => null,
        'created_by_user_id' => $this->userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    \Illuminate\Database\Capsule\Manager::table('group_memberships')->insert([
        'group_id' => $this->groupId,
        'user_id' => $this->userId,
        'role' => GroupMembership::ROLE_OWNER,
        'joined_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
});

test('normaliseArchetype returns the default for null and the value for known names', function (): void {
    expect($this->service->normaliseArchetype(null))
        ->toBe(Archetype::Collaborative->value);
    expect($this->service->normaliseArchetype('researcher'))
        ->toBe('researcher');
    expect($this->service->normaliseArchetype('ensemble'))
        ->toBe('ensemble');
});

test('normaliseArchetype throws on unknown values', function (): void {
    $this->service->normaliseArchetype('astronaut');
})->throws(InvalidArgumentException::class, "Unknown archetype 'astronaut'");

test('normalisePalette returns the default for null and the value for known names', function (): void {
    expect($this->service->normalisePalette(null))
        ->toBe(Palette::Slate->value);
    expect($this->service->normalisePalette('violet'))
        ->toBe('violet');
});

test('normalisePalette throws on unknown values', function (): void {
    $this->service->normalisePalette('rainbow');
})->throws(InvalidArgumentException::class, "Unknown palette_key 'rainbow'");

test('normaliseVariantKey accepts v0, v1, v2 and null', function (): void {
    expect($this->service->normaliseVariantKey(null))->toBeNull();
    expect($this->service->normaliseVariantKey('v0'))->toBe('v0');
    expect($this->service->normaliseVariantKey('v1'))->toBe('v1');
    expect($this->service->normaliseVariantKey('v2'))->toBe('v2');
});

test('normaliseVariantKey throws on values outside v0..v2', function (): void {
    $this->service->normaliseVariantKey('v9');
})->throws(InvalidArgumentException::class);

test('resolveVariantKey is deterministic per seed', function (): void {
    expect($this->service->resolveVariantKey(1))->toBe($this->service->resolveVariantKey(1));
});

test('resolveVariantKey always returns one of v0, v1, v2', function (): void {
    for ($i = 0; $i < 20; $i++) {
        expect($this->service->resolveVariantKey($i))->toMatch('/^v[0-2]$/');
    }
});

test('fnv1a is deterministic and yields different values for different seeds', function (): void {
    // fnv1a() stringifies its input, so the offset basis (0x811c9dc5)
    // is only reached for the empty string — which the int signature
    // cannot produce. We assert determinism + non-collision instead.
    expect($this->service->fnv1a(1))->toBe($this->service->fnv1a(1));
    expect($this->service->fnv1a(1))->not->toBe($this->service->fnv1a(2));
});

test('imageWireShape returns null fields for a missing asset', function (): void {
    $wire = $this->service->imageWireShape(null);
    expect($wire['kind'])->toBe('image');
    expect($wire['archetype'])->toBeNull();
    expect($wire['variant_key'])->toBeNull();
    expect($wire['palette_key'])->toBeNull();
    expect($wire['fg_color'])->toBeNull();
    expect($wire['bg_color'])->toBeNull();
    expect($wire['image_url'])->toBeNull();
    expect($wire['image_updated_at'])->toBeNull();
});

test('imageWireShape returns the asset URL when one is provided', function (): void {
    $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $asset = new MediaAsset();
    $asset->id = $id;
    $asset->asset_url = "/api/v1/assets/{$id}.png";
    $asset->updated_at = Carbon::parse('2025-01-01T00:00:00+00:00');

    $wire = $this->service->imageWireShape($asset);
    expect($wire['kind'])->toBe('image');
    expect($wire['image_url'])->toBe("/api/v1/assets/{$id}.png");
    expect($wire['image_updated_at'])->toBe('2025-01-01T00:00:00+00:00');
});

test('defaultWireShape falls back to the subject default archetype + palette', function (): void {
    $wire = $this->service->toWireShape($this->groupId);
    expect($wire['kind'])->toBe('avatar');
    expect($wire['archetype'])->toBe(Archetype::Collaborative->value);
    expect($wire['palette_key'])->toBe(Palette::Slate->value);
    expect($wire['variant_key'])->toMatch('/^v[0-2]$/');
});

test('toWireShape resolves variant_key from fnv1a(seed) when none was picked', function (): void {
    $this->service->updateAvatar($this->groupId, 'researcher', null, 'violet');

    $wire = $this->service->toWireShape($this->groupId);

    expect($wire['variant_key'])->toMatch('/^v[0-2]$/');
});

test('ProfilePictureService is abstract', function (): void {
    $reflection = new ReflectionClass(ProfilePictureService::class);
    expect($reflection->isAbstract())->toBeTrue();
});
