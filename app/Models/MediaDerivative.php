<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Join row linking a derivative `media_assets` row to its source asset.
 *
 * Natural key `(parent_id, format, producer_plugin, producer_operation)`
 * makes re-rendering idempotent — see
 * {@see \Spora\Services\MediaArchive\MediaDerivativeService::create()}.
 *
 * @property string                           $id
 * @property string                           $parent_id
 * @property string                           $derivative_id
 * @property string                           $format
 * @property string|null                      $producer_plugin
 * @property string|null                      $producer_operation
 * @property Carbon|null                      $created_at
 * @property Carbon|null                      $updated_at
 * @property MediaAsset|null                  $parent
 * @property MediaAsset|null                  $derivative
 */
final class MediaDerivative extends Model
{
    /** @var string */
    protected $table = 'media_derivatives';

    /** @var string */
    protected $primaryKey = 'id';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'parent_id',
        'derivative_id',
        'format',
        'producer_plugin',
        'producer_operation',
        'created_at',
        'updated_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'parent_id');
    }

    public function derivative(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'derivative_id');
    }
}
