<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A principal container: a set of {@see User} members with tiered RBAC roles
 * (`owner` / `admin` / `member`) that can collectively own agents and
 * settings through their {@see Principal} pointer.
 *
 * Roles are ranked owner > admin > member, and the rank gates service-layer
 * operations (`changeMemberRole`, `removeMember`, `deleteGroup`, etc.). The
 * ENUM column enforces the universe; the rank logic lives in
 * {@see \Spora\Services\GroupService}.
 *
 * @property int          $id
 * @property string       $name
 * @property string|null  $description
 * @property int          $created_by_user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class Group extends Model
{
    protected $table = 'groups';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'created_by_user_id'];

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function principal(): HasOne
    {
        return $this->hasOne(Principal::class);
    }

    public function picture(): HasOne
    {
        return $this->hasOne(GroupPicture::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
