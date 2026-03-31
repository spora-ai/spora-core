<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property int         $task_id
 * @property int         $sequence
 * @property string      $role
 * @property string|null $content
 * @property string|null $tool_call_id
 * @property string|null $tool_name
 * @property string|null $tool_call_payload
 * @property int|null    $input_tokens
 * @property int|null    $output_tokens
 * @property Carbon|null $created_at
 */
class TaskHistory extends Model
{
    /** @var string */
    protected $table = 'task_history';

    // Append-only: no updated_at column
    public const UPDATED_AT = null;

    /** @var list<string> */
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
