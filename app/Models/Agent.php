<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property-read User|null $user
 * @property string $name
 * @property string|null $description
 * @property string|null $recipe_id
 * @property string|null $system_prompt
 * @property int|null $llm_driver_config_id
 * @property int|null $max_steps
 * @property bool $is_active
 * @property int $retry_after_minutes
 * @property int $max_retries
 */
final class Agent extends Model
{
    protected $table = 'agents';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'recipe_id',
        'system_prompt',
        'llm_driver_config_id',
        'max_steps',
        'is_active',
        'allow_followup',
        'retry_after_minutes',
        'max_retries',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_steps' => 'integer',
        'llm_driver_config_id' => 'integer',
        'allow_followup' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function agentTools(): HasMany
    {
        return $this->hasMany(AgentTool::class);
    }

    public function agentToolOverrides(): HasMany
    {
        return $this->hasMany(AgentToolOverride::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class);
    }
}
