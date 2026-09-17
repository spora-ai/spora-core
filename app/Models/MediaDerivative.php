<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Join row that links a derivative `media_assets` row back to the
 * source asset it was produced from.
 *
 * The natural key on `(parent_id, format, producer_plugin, producer_operation)`
 * makes re-rendering the same source through the same producer idempotent
 * — {@see \Spora\Services\MediaArchive\MediaDerivativeService::create()}
 * refreshes the existing derivative's bytes rather than stacking a new
 * row. Both FKs cascade on delete so removing either side cleans the
 * join automatically.
 *
 * `principal_id` is intentionally not duplicated here: it lives on the
 * derivative's own `media_assets` row (inherited via
 * {@see MediaDerivativeService::createNew()}).
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
