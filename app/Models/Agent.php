<?php

declare(strict_types=1);

namespace Spora\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spora\Drivers\DriverFactory;
use Throwable;

/**
 * @property int $id
 * @property int|null $user_id
 * @property-read User|null $user
 * @property string $name
 * @property string|null $description
 * @property string|null $system_prompt
 * @property int|null $llm_driver_config_id
 * @property int|null $max_steps
 * @property bool $is_active
 * @property bool $allow_followup
 * @property int $retry_after_minutes
 * @property int $max_retries
 * @property bool $is_pinned
 * @property bool $is_archived
 * @property DateTimeInterface|null $created_at
 * @property DateTimeInterface|null $updated_at
 */
final class Agent extends Model
{
    protected $table = 'agents';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'system_prompt',
        'llm_driver_config_id',
        'max_steps',
        'is_active',
        'allow_followup',
        'retry_after_minutes',
        'max_retries',
        'is_pinned',
        'is_archived',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_steps' => 'integer',
        'llm_driver_config_id' => 'integer',
        'allow_followup' => 'boolean',
        'is_pinned' => 'boolean',
        'is_archived' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    /**
     * Image-input capability for the agent's configured LLM.
     *
     * Pass `null` (the default) when no DriverFactory is wired — e.g.
     * in tests that haven't built the full app container. Returns
     * `false` on any error (no driver, decrypt failure, …) so the call
     * site never has to translate exceptions into capability flags.
     */
    public function supportsImageInput(?DriverFactory $factory = null): bool
    {
        if ($factory === null) {
            return false;
        }
        try {
            $driver = $factory->makeFromAgent($this);
        } catch (Throwable) {
            return false;
        }
        return $driver->supportsImageInput();
    }
}
