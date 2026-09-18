<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persists {@see \Spora\Drivers\ValueObjects\Usage} — the VO is the
 * typed contract, this model is the persistence handle. Append-only:
 * no `updated_at` column on the table.
 *
 * @property int                                 $id
 * @property int                                 $task_history_id
 * @property int                                 $input_tokens
 * @property int                                 $output_tokens
 * @property int                                 $reasoning_tokens
 * @property int                                 $cached_tokens
 * @property int                                 $cache_creation_tokens
 * @property int                                 $cache_read_tokens
 * @property string                              $provider
 * @property array<string, mixed>|null           $raw_usage
 * @property array<string, mixed>|null           $driver_meta_info
 * @property Carbon|null                         $created_at
 * @property TaskHistory|null                    $taskHistory
 */
final class Usage extends Model
{
    /** @var string */
    protected $table = 'usage';

    // Append-only: no `updated_at` column on the table.
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'task_history_id',
        'input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'cached_tokens',
        'cache_creation_tokens',
        'cache_read_tokens',
        'provider',
        'raw_usage',
        'driver_meta_info',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'task_history_id'       => 'integer',
        'input_tokens'          => 'integer',
        'output_tokens'         => 'integer',
        'reasoning_tokens'      => 'integer',
        'cached_tokens'         => 'integer',
        'cache_creation_tokens' => 'integer',
        'cache_read_tokens'     => 'integer',
        'raw_usage'             => 'array',
        'driver_meta_info'      => 'array',
        'created_at'            => 'datetime',
    ];

    public function taskHistory(): BelongsTo
    {
        return $this->belongsTo(TaskHistory::class, 'task_history_id');
    }
}
