<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolCall extends Model
{
    protected $table = 'tool_calls';

    protected $fillable = [
        'task_id',
        'agent_id',
        'provider_call_id',
        'tool_name',
        'tool_class',
        'tool_type',
        'status',
        'proposed_arguments',
        'human_description',
        'approved_arguments',
        'result_content',
        'result_data',
        'approved_by',
        'approval_note',
        'executed_at',
    ];

    protected $casts = [
        'proposed_arguments' => 'array',
        'approved_arguments' => 'array',
        'result_data'        => 'array',
        'executed_at'        => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
