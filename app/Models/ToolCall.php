<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int              $id
 * @property int              $task_id
 * @property int              $agent_id
 * @property string           $provider_call_id
 * @property string           $tool_name
 * @property string           $tool_class
 * @property string           $tool_type
 * @property string           $status
 * @property array<string,mixed>      $proposed_arguments
 * @property string|null      $human_description
 * @property array<string,mixed>|null $approved_arguments
 * @property string|null      $result_content
 * @property array<string,mixed>|null $result_data
 * @property int|null         $approved_by
 * @property string|null      $approval_note
 * @property Carbon|null      $executed_at
 * @property Carbon|null      $created_at
 * @property Carbon|null      $updated_at
 */
final class ToolCall extends Model
{
    /** @var string */
    protected $table = 'tool_calls';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'agent_id',
        'provider_call_id',
        'tool_name',
        'tool_class',
        'tool_type',
        'operation',
        'operation_description',
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

    /** @var array<string, string> */
    protected $casts = [
        'proposed_arguments'      => 'array',
        'approved_arguments'      => 'array',
        'result_data'             => 'array',
        'operation'              => 'string',
        'operation_description'  => 'string',
        'executed_at'            => 'datetime',
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
