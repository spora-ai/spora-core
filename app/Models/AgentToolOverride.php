<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @method static \Illuminate\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null)
 */
class AgentToolOverride extends Model
{
    /** @var string */
    protected $table = 'agent_tool_overrides';

    /** @var list<string> */
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
