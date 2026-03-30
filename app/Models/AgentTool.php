<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTool extends Model
{
    protected $table = 'agent_tools';

    protected $fillable = [
        'agent_id',
        'tool_class',
        'tool_name',
        'auto_approve',
    ];

    protected $casts = [
        'auto_approve' => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
