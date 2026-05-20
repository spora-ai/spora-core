<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $agent_id
 * @property string $name
 * @property string|null $summary
 * @property string|null $content
 * @property int $order
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property Agent|null $agent
 */
final class Memory extends Model
{
    protected $table = 'memories';

    protected $fillable = [
        'user_id',
        'agent_id',
        'name',
        'summary',
        'content',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Memory> $query
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('agent_id');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<Memory> $query
     */
    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }
}
