<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credential for one of the API surfaces.
 *
 * `id` is the 16-byte random hex embedded in the presented key, and
 * `secret_hash` is the only trace of the secret itself — a SHA-256 hex
 * digest, compared with `hash_equals` and never sent over the wire.
 * Deleting a key is a hard delete, which cascades its pending callbacks.
 *
 * `scope` says what the key may reach and which of `agent_id` / `group_id`
 * names the target; see {@see \Spora\Auth\ApiKeyScope} for the rule and for
 * why it is a column rather than something a reader infers.
 *
 * @property string      $id
 * @property string      $scope
 * @property int|null    $agent_id
 * @property int|null    $group_id
 * @property string      $name
 * @property string      $secret_hash
 * @property \Carbon\Carbon|null $last_used_at
 * @property int|null    $created_by
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property Agent|null  $agent
 * @property Group|null  $group
 * @property User|null   $createdBy
 */
final class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $fillable = [
        'id',
        'scope',
        'agent_id',
        'group_id',
        'name',
        'secret_hash',
        'last_used_at',
        'created_by',
    ];

    protected $casts = [
        'agent_id' => 'integer',
        'group_id' => 'integer',
        'created_by' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Both are non-defaults for the same reason: the id is supplied by
     * {@see \Spora\Auth\ApiKeyIssuer}, not the DB.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
