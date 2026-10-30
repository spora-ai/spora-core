<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spora\Models\Concerns\HasStringColumnLengthValidation;

/**
 * A folder in the Media Archive.
 *
 * Folders are principal-scoped and form a tree: `parent_id` points at the
 * enclosing folder (null for a top-level one) and `path` carries the
 * folder's path from the tenant root (`/Corporate`, `/Corporate/2026`).
 *
 * The UNIQUE `(principal_id, path)` index is what makes sibling names
 * unique — the same name may exist under two different parents, but two
 * siblings with one name collide. `path` is therefore the identity a
 * rename or a move rewrites, together with the paths of every descendant.
 *
 * @property string                $id
 * @property int                   $principal_id
 * @property string|null           $parent_id
 * @property string                $name
 * @property string                $path
 * @property \Carbon\Carbon|null   $created_at
 * @property \Carbon\Carbon|null   $updated_at
 * @property Principal|null        $principal
 * @property MediaFolder|null      $parent
 * @property \Illuminate\Database\Eloquent\Collection<int, MediaFolder> $children
 * @property \Illuminate\Database\Eloquent\Collection<int, MediaAsset>   $assets
 */
final class MediaFolder extends Model
{
    use HasStringColumnLengthValidation;

    /**
     * Single source of truth for the columns persisted on a
     * {@see MediaFolder} row. Drives both `$fillable` and the
     * `@property` docblock above (kept in sync manually).
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'id',
        'principal_id',
        'parent_id',
        'name',
        'path',
    ];

    /**
     * Columns that need Eloquent type coercion. Drives `$casts`.
     *
     * @var array<string, string>
     */
    public const CASTS = [
        'principal_id' => 'integer',
    ];

    /** @var string */
    protected $table = 'media_folders';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = self::COLUMNS;

    /** @var array<string, string> */
    protected $casts = self::CASTS;

    /**
     * `name` and `path` are the only bounded text columns; `parent_id` is
     * a UUID and is not a string column the model validates.
     *
     * @var array<string, int>
     */
    public const STRING_COLUMN_MAX_LENGTHS = [
        'name' => 255,
        'path' => 512,
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<MediaFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<MediaAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'folder_id');
    }

    /** @return array{0: string, 1: string} */
    protected function stringColumnsFitContext(): array
    {
        return ['media_folders', "media folder {$this->id}"];
    }
}
