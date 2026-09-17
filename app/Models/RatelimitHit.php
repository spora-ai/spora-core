<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hit row for the DB-backed sliding-window rate limiter used by `/tick`
 * and `/housekeeping`. Composite PK `(key, hit_at)` keeps inserts hot
 * while allowing `DELETE … WHERE hit_at < ?` GC.
 *
 * Eloquent can't model a true composite PK; `key` is declared as the
 * model's $primaryKey and the DB enforces the full uniqueness.
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
