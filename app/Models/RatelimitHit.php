<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hit row for the DB-backed sliding-window rate limiter used by `/tick`,
 * `/housekeeping` and the Agent API. Nothing reads a hit by primary key —
 * the limiter only inserts and counts — so the surrogate `id` exists purely
 * to give two hits in the same second their own row. The `(key, hit_at)`
 * index carries the window query.
 *
 * @property int     $id
 * @property string  $key
 * @property Carbon  $hit_at
 */
final class RatelimitHit extends Model
{
    /** @var string */
    protected $table = 'ratelimit_hits';

    /** @var string */
    protected $primaryKey = 'id';

    /** @var bool */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'hit_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'hit_at' => 'datetime',
    ];
}
