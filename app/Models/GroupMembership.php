<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single (group, user, role) triple. Combined with the {@see Group} model,
 * this is the durable side of the share semantics: a {@see User} becomes a
 * member of a {@see Group} (with role `owner` / `admin` / `member`) and
 * therefore a member of the Group's {@see Principal}, granting visibility and
 * admin rights over the agents and settings that principal owns.
 *
 * Roles are ranked `owner > admin > member`. Rank checks live in
 * {@see \Spora\Services\GroupService}; the ENUM column keeps the universe
 * stable at the storage layer.
 *
 * @property int          $id
 * @property int          $group_id
 * @property int          $user_id
 * @property string       $role      'owner' | 'admin' | 'member'
 * @property \Carbon\Carbon|null $joined_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class GroupMembership extends Model
{
    protected $table = 'group_memberships';

    public const ROLE_OWNER  = 'owner';
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_MEMBER = 'member';

    /** Ranked high → low; service-layer logic uses `>=` to compare. */
    public const ROLE_RANK = [
        self::ROLE_OWNER  => 3,
        self::ROLE_ADMIN  => 2,
        self::ROLE_MEMBER => 1,
    ];

    /** @var list<string> */
    protected $fillable = ['group_id', 'user_id', 'role', 'joined_at'];

    /** @var array<string, string> */
    protected $casts = [
        'joined_at'  => 'datetime',
        'group_id'   => 'integer',
        'user_id'    => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function rank(string $role): int
    {
        return self::ROLE_RANK[$role] ?? 0;
    }
}
