<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $table = 'agents';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'recipe_id',
        'llm_provider',
        'llm_model',
        'llm_base_url',
        'max_steps',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_steps' => 'integer',
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
