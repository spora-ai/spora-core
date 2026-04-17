<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $agent_id
 * @property int|null $template_id
 * @property string|null $raw_prompt
 * @property string|null $cron_expression
 * @property \Carbon\Carbon|null $run_at
 * @property string $timezone
 * @property int|null $max_steps_override
 * @property bool $is_active
 * @property \Carbon\Carbon|null $last_run_at
 * @property \Carbon\Carbon|null $next_run_at
 * @property int $user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class ScheduledRun extends Model
{
    protected $table = 'scheduled_runs';

    protected $fillable = [
        'agent_id',
        'template_id',
        'raw_prompt',
        'cron_expression',
        'run_at',
        'timezone',
        'max_steps_override',
        'is_active',
        'last_run_at',
        'next_run_at',
        'user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'max_steps_override' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AgentPromptTemplate::class, 'template_id');
    }
}
