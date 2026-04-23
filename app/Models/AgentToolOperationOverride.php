<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $agent_id
 * @property string $tool_class
 * @property string $operation
 * @property int|null $enabled
 * @property int|null $default_requires_approval
 */
final class AgentToolOperationOverride extends Model
{
    protected $table = 'agent_tool_operation_overrides';

    protected $fillable = [
        'agent_id',
        'tool_class',
        'operation',
        'enabled',
        'default_requires_approval',
    ];

    protected $casts = [
        'enabled'                  => 'int',
        'default_requires_approval' => 'int',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
