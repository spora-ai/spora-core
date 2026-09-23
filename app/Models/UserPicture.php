<?php

declare(strict_types=1);

namespace Spora\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User profile picture — 1:1 with a User.
 *
 * Always carries a `media_path` (relative to `<storage>/`) pointing at
 * the single uploaded image for this user. There is no archetype /
 * variant / palette branch: when no row exists, the SPA renders initials
 * via {@see \Spora\Frontend\Avatar}, the same fallback it uses today.
 *
 * The bytes are served by {@see \Spora\Http\UserPictureAssetController}
 * to any logged-in user — visibility is deliberately not principal-
 * scoped, which is what motivated routing through a separate table
 * rather than reusing `media_assets` (see 0086_create_user_pictures_table
 * for the full rationale).
 *
 * Columns are populated by {@see \Spora\Services\UserPictures\UserPictureService};
 * callers should never `UserPicture::create([...])` directly.
 *
 * @property int $id
 * @property int $user_id
 * @property string $media_path
 * @property string $mime
 * @property int $size_bytes
 * @property DateTimeInterface|null $created_at
 * @property DateTimeInterface|null $updated_at
 */
final class UserPicture extends Model
{
    protected $table = 'user_pictures';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'media_path',
        'mime',
        'size_bytes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'user_id'    => 'integer',
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
