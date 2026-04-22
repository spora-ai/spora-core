<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $scheduled_run_id
 * @property \Carbon\Carbon $due_at
 * @property string $status
 * @property \Carbon\Carbon|null $claimed_at
 * @property \Carbon\Carbon|null $completed_at
 * @property int|null $task_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class ScheduledRunNext extends Model
{
    protected $table = 'scheduled_runs_next';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CLAIMED = 'CLAIMED';
    public const STATUS_DONE = 'DONE';
    public const STATUS_SKIPPED = 'SKIPPED';

    protected $fillable = [
        'scheduled_run_id',
        'due_at',
        'status',
        'claimed_at',
        'completed_at',
        'task_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scheduledRun(): BelongsTo
    {
        return $this->belongsTo(ScheduledRun::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
