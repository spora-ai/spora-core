<?php

declare(strict_types=1);

namespace Spora\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spora\Models\Concerns\HasStringColumnLengthValidation;
use Throwable;

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
 * @property string|null $effort
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
    use HasStringColumnLengthValidation;

    /**
     * The column's own spelling of a timestamp.
     *
     * `retry_after` is a UTC column, and the worker daemon's claim query
     * compares it against `gmdate()` — so the value that goes in and the
     * value that comes out are spelled the same way.
     */
    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

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
        'effort',
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
     * The principal owner of this task. Use this for the Mercure
     * `principal/{principalId}/tasks` topic so every principal viewer
     * (group members included) receives the event. Replaces the
     * user-keyed topic format that only reached the trigger user.
     */
    public function principalOwnerId(): int
    {
        return (int) $this->principal_id;
    }

    /**
     * The user who triggered this task. Replaces the misleadingly-named
     * `principalUserId()` for the user-keyed notification topic.
     */
    public function triggerUserId(): ?int
    {
        return $this->trigger_user_id !== null ? (int) $this->trigger_user_id : null;
    }

    /**
     * The moment a pending retry becomes due, read as the instant it names.
     *
     * `retry_after` is a UTC column: the worker daemon's claim query
     * compares it against `gmdate()`, so the stored string is UTC.
     * Eloquent's `datetime` cast parses it in the process default timezone
     * instead — and `Kernel` never calls `date_default_timezone_set()` — so
     * on a host whose php.ini names a local zone the stamp comes back an
     * hour (or more) off, and the countdown the browser shows is wrong by
     * that much. Parsed as UTC here, the way
     * {@see \Spora\Http\Middleware\ApiKeyAuthenticator} reads `last_used_at`.
     *
     * The value the model currently holds is read rather than the last
     * synchronised snapshot: the snapshot cannot see an assignment that
     * has not been saved, and the model in hand is the truth about the
     * row. {@see setRetryAfterAttribute()} spells every assignment in
     * UTC, so the instant survives that assignment.
     */
    public function retryAfterUtc(): ?Carbon
    {
        $raw = $this->attributes['retry_after'] ?? null;

        if ($raw instanceof DateTimeInterface) {
            // A model built from an array holds the instant itself; the
            // column holds its UTC spelling. Normalising to that spelling
            // here lets both go through the one parse below.
            $raw = Carbon::instance($raw)->utc()->format(self::TIMESTAMP_FORMAT);
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat(self::TIMESTAMP_FORMAT, $raw, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The column is UTC, so an assignment is spelled in UTC.
     *
     * The `datetime` cast would write the wall clock of whatever timezone
     * the assigned value carries — an hour off on a host that is not UTC,
     * and the worker's claim query compares the column against `gmdate()`.
     * A string is the column's own spelling and is kept as it is; a date
     * object carries the instant and is converted.
     */
    protected function setRetryAfterAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['retry_after'] = null;
            return;
        }

        if ($value instanceof DateTimeInterface) {
            $this->attributes['retry_after'] = Carbon::instance($value)->utc()->format(self::TIMESTAMP_FORMAT);
            return;
        }

        $this->attributes['retry_after'] = (string) $value;
    }

    /**
     * @deprecated Use {@see principalOwnerId()} for Mercure task events
     *             or {@see triggerUserId()} for the user-keyed notification
     *             topic. Retained during the Plan B migration window; will
     *             be removed once all callers are swept.
     */
    public function principalUserId(): ?int
    {
        return $this->triggerUserId();
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

    /** @var array<string, int> */
    public const STRING_COLUMN_MAX_LENGTHS = [
        'status'         => 30,
        'failure_reason' => 1000,
        'error_code'     => 30,
    ];

    /** @return array{0: string, 1: string} */
    protected function stringColumnsFitContext(): array
    {
        return ['tasks', "task #{$this->id}"];
    }
}
