<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user favourite toggle. Replaces the shared `agents.is_favorite`
 * column (migration 0058) which leaked across every member of a group:
 * any member could flip the toggle and every other member saw the
 * change. The pivot makes favourites private to each user.
 *
 * Composite primary key (`user_id`, `agent_id`) — no surrogate id. The
 * relations use the composite key as the parent reference.
 *
 * @property int $user_id
 * @property int $agent_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class UserAgentFavorite extends Model
{
    /** @var string */
    protected $table = 'user_agent_favorites';

    /** @var list<string> */
    protected $fillable = ['user_id', 'agent_id', 'created_at'];

    /**
     * Disable Eloquent's auto-incrementing PK lookup — the composite PK
     * makes `$incrementing` irrelevant and the `$primaryKey` would be
     * wrong if we set it.
     */
    public $incrementing = false;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $keyType = 'int';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }
}
