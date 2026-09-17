<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-hit row for the DB-backed sliding-window rate limiter
 * ({@see \Spora\Services\DbRateLimiter}) used by `/tick` and
 * `/housekeeping`. The composite primary key `(key, hit_at)` keeps
 * inserts hot while allowing `DELETE … WHERE hit_at < ?` GC to drain
 * old rows without index churn.
 *
 * Eloquent can't model a true composite PK; we declare `key` as the
 * model's `$primaryKey` and let the DB enforce `(key, hit_at)`
 * uniqueness through the composite index. `save()` issues a plain
 * INSERT so two rows with the same `key` and different `hit_at`
 * coexist as the DB-side composite PK expects.
 *
 * @property string  $key
 * @property Carbon  $hit_at
 */
final class RatelimitHit extends Model
{
    /** @var string */
    protected $table = 'ratelimit_hits';

    /** @var string */
    protected $primaryKey = 'key';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

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
