<?php

declare(strict_types=1);

namespace Spora\Models;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spora\Drivers\DriverFactory;
use Spora\Services\PrincipalResolver;
use Throwable;

/**
 * @property int $id
 * @property int $principal_id
 * @property-read Principal|null $principal
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
 * @property bool $is_favorite
 * @property string|null $notes
 * @property DateTimeInterface|null $created_at
 * @property DateTimeInterface|null $updated_at
 *
 * Migration 0067 dropped `agents.user_id` and replaced it with
 * `principal_id`. Consumer code that still reads `$agent->user_id`
 * hits `getUserIdAttribute()` below, which resolves via the principal.
 * Kept temporarily so downstream consumers can be refactored in their
 * own PRs. New code must use `principal_id` (or
 * `PrincipalResolver::ownerUserId()` / `AgentManifest` etc.).
 *
 * @property-read int|null $user_id Legacy alias for the principal's owner user id.
 */
final class Agent extends Model
{
    protected $table = 'agents';

    protected $fillable = [
        'principal_id',
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
        'is_favorite',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_steps' => 'integer',
        'principal_id' => 'integer',
        'llm_driver_config_id' => 'integer',
        'allow_followup' => 'boolean',
        'is_pinned' => 'boolean',
        'is_archived' => 'boolean',
        'is_favorite' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /**
     * Legacy `user` relation — migration 0067 routed ownership through
     * the principal table, so the direct `User` FK is gone. For a
     * user-principal this returns the matching `User`; for a
     * group-principal it returns the first `owner` user so legacy code
     * paths still get a User instance. Kept temporarily while downstream
     * consumers are migrated in their own PRs.
     */
    public function user(): ?BelongsTo
    {
        $principal = Principal::find($this->principal_id);
        if ($principal === null) {
            return null;
        }

        if ($principal->type === Principal::TYPE_USER) {
            return $this->belongsTo(User::class, 'principal_id', 'id')
                ->where('id', $principal->user_id);
        }

        $ownerUserId = Capsule::table('group_memberships')
            ->where('group_id', $principal->group_id)
            ->where('role', GroupMembership::ROLE_OWNER)
            ->orderBy('id')
            ->value('user_id');

        return $ownerUserId !== null
            ? $this->belongsTo(User::class, 'principal_id', 'id')->where('id', $ownerUserId)
            : null;
    }

    public function getUserAttribute(): ?User
    {
        $relation = $this->user();
        if ($relation === null) {
            return null;
        }
        $user = $relation->first();
        return $user instanceof User ? $user : null;
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

    public function profilePicture(): HasOne
    {
        return $this->hasOne(AgentPicture::class, 'agent_id');
    }

    /**
     * Legacy alias for `$agent->user_id`. Migration 0067 cut the column
     * but downstream consumers (controllers, tools, workers) still read
     * it. The accessor delegates to {@see PrincipalResolver::ownerUserId()}
     * so the principals-and-groups resolution (user-principal user_id or
     * group-principal first owner) lives in exactly one place. The
     * result is memoised per model instance so repeated reads in the
     * same request don't re-issue the principal lookup.
     *
     * Read-only. New code must use {@see PrincipalResolver::ownerUserId()}
     * directly so the principals-and-groups model is explicit.
     */
    public function getUserIdAttribute(): ?int
    {
        if ($this->userIdCacheResolved) {
            return $this->userIdCache;
        }
        $resolved = (new PrincipalResolver())->ownerUserId((int) $this->principal_id);
        $this->userIdCache = $resolved;
        $this->userIdCacheResolved = true;
        return $resolved;
    }

    private ?int $userIdCache = null;

    private bool $userIdCacheResolved = false;

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
