<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskHistory extends Model
{
    protected $table = 'task_history';

    // Append-only: no updated_at column
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id',
        'sequence',
        'role',
        'content',
        'tool_call_id',
        'tool_name',
        'tool_call_payload',
        'input_tokens',
        'output_tokens',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
