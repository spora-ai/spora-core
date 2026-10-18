<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credential for one agent's Agent API surface.
 *
 * `id` is the 16-byte random hex embedded in the presented key, and
 * `secret_hash` is the only trace of the secret itself — a SHA-256 hex
 * digest, compared with `hash_equals` and never sent over the wire.
 * Deleting a key is a hard delete, which cascades its pending callbacks.
 *
 * @property string      $id
 * @property int         $agent_id
 * @property string      $name
 * @property string      $secret_hash
 * @property \Carbon\Carbon|null $last_used_at
 * @property int|null    $created_by
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property Agent|null  $agent
 * @property User|null   $createdBy
 */
final class AgentApiKey extends Model
{
    protected $table = 'agent_api_keys';

    protected $fillable = [
        'id',
        'agent_id',
        'name',
        'secret_hash',
        'last_used_at',
        'created_by',
    ];

    protected $casts = [
        'agent_id' => 'integer',
        'created_by' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * `incrementing` and `$keyType` stay at Eloquent's defaults: the id
     * is supplied by {@see \Spora\Auth\AgentApiKeyIssuer}, not the DB.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
