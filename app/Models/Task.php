<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $agent_id
 * @property int         $user_id
 * @property string      $status
 * @property string      $user_prompt
 * @property string|null $final_response
 * @property int         $step_count
 * @property int         $max_steps
 * @property string|null $pending_state
 * @property string|null $failure_reason
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null    $parent_task_id
 * @property int|null    $retry_of_task_id
 * @property int         $retry_count
 * @property Carbon|null $retry_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Task extends Model
{
    /** @var string */
    protected $table = 'tasks';

    /** @var list<string> */
    protected $fillable = [
        'agent_id',
        'user_id',
        'status',
        'user_prompt',
        'final_response',
        'step_count',
        'max_steps',
        'pending_state',
        'failure_reason',
        'error_code',
        'error_message',
        'parent_task_id',
        'retry_of_task_id',
        'retry_count',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'step_count'       => 'integer',
        'max_steps'        => 'integer',
        'retry_count'      => 'integer',
        'retry_of_task_id' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TaskHistory, $this> */
    public function taskHistory(): HasMany
    {
        return $this->hasMany(TaskHistory::class);
    }

    /** @return HasMany<ToolCall, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }
}
