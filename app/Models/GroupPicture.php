<?php

declare(strict_types=1);

namespace Spora\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Group profile picture — 1:1 with a Group.
 *
 * Mirrors {@see AgentPicture} — a picture is either an archetype avatar
 * (icon + variant + palette) or an uploaded image (FK to media_assets).
 * The XOR invariant is enforced in
 * {@see \Spora\Services\ProfilePictures\GroupPictureService}, not at the
 * DB level, so the schema stays simple and the rule is co-located with
 * the rest of the picture logic.
 *
 * Columns are populated by the service — callers should never
 * `GroupPicture::create([...])` directly. The service performs the
 * validation, the resolution of `variant_key` from `group_id` (when
 * unset), and the `media_asset_id` swap on image attach/detach.
 *
 * @property int $id
 * @property int $group_id
 * @property string|null $archetype
 * @property string|null $variant_key
 * @property string|null $palette_key
 * @property string|null $media_asset_id
 * @property DateTimeInterface|null $created_at
 * @property DateTimeInterface|null $updated_at
 */
final class GroupPicture extends Model
{
    protected $table = 'group_pictures';

    protected $fillable = [
        'group_id',
        'archetype',
        'variant_key',
        'palette_key',
        'media_asset_id',
    ];

    protected $casts = [
        'group_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
