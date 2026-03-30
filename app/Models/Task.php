<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $table = 'tasks';

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
    ];

    protected $casts = [
        'step_count' => 'integer',
        'max_steps'  => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taskHistory(): HasMany
    {
        return $this->hasMany(TaskHistory::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class);
    }
}
