<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A callback the Agent API still owes its receiver, one row per chat.
 *
 * The row is created when the chat is created — while the task is
 * `QUEUED` — so delivery needs no task-lifecycle hook. See the migration
 * for why. `pending_status` is what is owed, `delivered_status` is what
 * the receiver last acknowledged, and the pair is what lets a chat that
 * settles twice deliver twice while a failed POST only retries.
 *
 * @property int         $id
 * @property int         $chat_id
 * @property string      $key_id
 * @property int         $agent_id
 * @property string      $url
 * @property string|null $pending_status
 * @property string|null $delivered_status
 * @property int         $attempts
 * @property \Carbon\Carbon|null $next_attempt_at
 * @property int|null    $last_status_code
 * @property string|null $last_error
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property Task|null   $chat
 * @property AgentApiKey|null $key
 */
final class AgentApiCallback extends Model
{
    protected $table = 'agent_api_callbacks';

    protected $fillable = [
        'chat_id',
        'key_id',
        'agent_id',
        'url',
        'pending_status',
        'delivered_status',
        'attempts',
        'next_attempt_at',
        'last_status_code',
        'last_error',
        'delivered_at',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'agent_id' => 'integer',
        'attempts' => 'integer',
        'last_status_code' => 'integer',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'chat_id');
    }

    public function key(): BelongsTo
    {
        return $this->belongsTo(AgentApiKey::class, 'key_id');
    }
}
