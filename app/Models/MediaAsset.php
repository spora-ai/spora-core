<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spora\Services\MediaArchive\MediaType;

/**
 * @property string                                  $id
 * @property int|null                               $agent_id
 * @property int|null                               $task_id
 * @property int|null                               $tool_call_id
 * @property string|null                            $plugin_slug
 * @property string|null                            $tool_name
 * @property string|null                            $media_type
 * @property string|null                            $mime_type
 * @property int|null                               $byte_size
 * @property int|null                               $width
 * @property int|null                               $height
 * @property float|null                             $duration_seconds
 * @property string|null                            $prompt
 * @property array<string>|null                      $tags
 * @property array<string, mixed>|null              $metadata
 * @property string                                 $asset_url
 * @property string|null                            $source_url
 * @property string                                 $storage_mode
 * @property \Carbon\Carbon|null                    $created_at
 * @property \Carbon\Carbon|null                    $updated_at
 * @property Agent|null                             $agent
 * @property Task|null                              $task
 *
 * Note: the @property docblock above mirrors {@see self::COLUMNS} plus
 * the Eloquent-managed `created_at`/`updated_at` and the relationship
 * accessors. Sonar flags it as duplicate of the const — kept in sync
 * manually because PHP can't reference a class constant in a docblock.
 */
final class MediaAsset extends Model
{
    /**
     * Single source of truth for the columns persisted on a
     * {@see MediaAsset} row. Drives both `$fillable` and the
     * `@property` docblock above (kept in sync manually).
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'id',
        'agent_id',
        'task_id',
        'tool_call_id',
        'plugin_slug',
        'tool_name',
        'media_type',
        'mime_type',
        'byte_size',
        'width',
        'height',
        'duration_seconds',
        'prompt',
        'tags',
        'metadata',
        'asset_url',
        'source_url',
        'storage_mode',
    ];

    /**
     * Columns that need Eloquent type coercion. Drives `$casts`.
     *
     * @var array<string, string>
     */
    public const CASTS = [
        'agent_id'         => 'integer',
        'task_id'          => 'integer',
        'tool_call_id'     => 'integer',
        'byte_size'        => 'integer',
        'width'            => 'integer',
        'height'           => 'integer',
        'duration_seconds' => 'float',
        'tags'             => 'array',
        'metadata'         => 'array',
    ];

    /** @var string */
    protected $table = 'media_assets';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = self::COLUMNS;

    /** @var array<string, string> */
    protected $casts = self::CASTS;

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Convenience accessor: the {@see MediaType} enum for the row's
     * `media_type` column. Returns `Unknown` for null/invalid values so
     * callers don't have to guard.
     */
    public function typedMediaType(): MediaType
    {
        $raw = $this->media_type;
        if (!is_string($raw) || $raw === '') {
            return MediaType::Unknown;
        }
        return MediaType::tryFrom($raw) ?? MediaType::Unknown;
    }
}
