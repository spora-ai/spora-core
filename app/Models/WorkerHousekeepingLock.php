<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton-row mutex that serialises `/worker/housekeeping` calls
 * across the cluster.
 *
 * The `id` column is always `1` — the table has no UNIQUE constraint,
 * but the migration's docblock marks it as a single-row pattern and
 * every callsite uses {@see SINGLETON_ID}. Without a model there was no
 * typed handle for the row; {@see \Spora\Services\HousekeepingLock} ran
 * raw `Capsule::table('worker_housekeeping_locks')` queries against it.
 *
 * `claimed_until` is the lease deadline (CAS-comparable as
 * `Y-m-d H:i:s` UTC); `claimed_by` is currently a placeholder for
 * future caller analytics.
 *
 * @property int        $id
 * @property Carbon     $claimed_until
 * @property int        $claimed_by
 */
final class WorkerHousekeepingLock extends Model
{
    public const SINGLETON_ID = 1;

    /** @var string */
    protected $table = 'worker_housekeeping_locks';

    /** @var string */
    protected $primaryKey = 'id';

    /** @var bool */
    public $incrementing = false;

    /** @var bool */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'claimed_until',
        'claimed_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'id'            => 'integer',
        'claimed_until' => 'datetime',
        'claimed_by'    => 'integer',
    ];
}
