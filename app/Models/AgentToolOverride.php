<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AgentToolOverride extends Model
{
    protected $table = 'agent_tool_overrides';

    protected $fillable = [
        'agent_id',
        'tool_class',
        'settings',
    ];

    // settings is intentionally NOT in $casts — all access via ToolConfigService
    // Do NOT add 'settings' => 'array' here.

    /**
     * Guard against direct access to the encrypted settings column.
     * All reads/writes must go through ToolConfigService.
     *
     * @throws LogicException
     */
    public function getSettingsAttribute(): never
    {
        throw new LogicException('Access tool settings via ToolConfigService, not directly.');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
