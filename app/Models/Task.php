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
 * @property int         $principal_id
 * @property int|null    $trigger_user_id
 * @property Agent       $agent
 * @property Principal   $principal
 * @property User|null   $triggerUser
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
 * @property array|null  $data
 * @property string|null $lease_owner
 * @property Carbon|null $lease_expires_at
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
        'principal_id',
        'trigger_user_id',
        'status',
        'user_prompt',
        'final_response',
        'step_count',
        'max_steps',
        'pending_state',
        'failure_reason',
        'error_code',
        'error_message',
        'lease_owner',
        'lease_expires_at',
        'parent_task_id',
        'retry_of_task_id',
        'retry_count',
        'retry_after',
        'data',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'step_count'       => 'integer',
        'max_steps'        => 'integer',
        'retry_count'      => 'integer',
        'retry_of_task_id' => 'integer',
        'retry_after'      => 'datetime',
        'lease_expires_at' => 'datetime',
        'data'             => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * The principal that owns this task. Mirrors `agents.principal_id`
     * at creation time and is updated on agent transfer so the new
     * owner inherits every run.
     */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /**
     * The user who clicked "Send" — the credential owner for the tick
     * and the attribution the UI shows as "started by". Nullable so
     * system-generated tasks (scheduled runs, future cron / webhooks)
     * can land without a human trigger. NOT updated on agent transfer:
     * historical "user X started this chat" attribution outlives
     * ownership changes.
     */
    public function triggerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trigger_user_id');
    }

    /**
     * Convenience accessor for the Mercure `user/{userId}/tasks` topic:
     * the topic still keys off `user_id` (the trigger user's id) so
     * `trigger_user_id` IS the user_id we want. Returns null when the
     * task has no trigger (system-generated).
     */
    public function principalUserId(): ?int
    {
        return $this->trigger_user_id !== null ? (int) $this->trigger_user_id : null;
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
