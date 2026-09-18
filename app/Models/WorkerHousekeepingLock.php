<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton-row mutex for `/worker/housekeeping`. `id` is always
 * {@see SINGLETON_ID}; `claimed_until` is the lease deadline,
 * CAS-comparable as `Y-m-d H:i:s` UTC.
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
