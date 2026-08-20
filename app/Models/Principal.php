<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Unifies "a thing that owns agents and settings" behind a single row.
 *
 * Each Principal points at exactly one of:
 *   - a {@see User}  (`type=user`,  `user_id=<…>`, `group_id=null`)
 *   - a {@see Group} (`type=group`, `user_id=null`, `group_id=<…>`)
 *
 * The XOR is enforced by {@see self::save()} (and {@see self::validateXor()})
 * rather than a `saving` model event, because Spora uses Illuminate\Database
 * Capsule standalone — the Event Dispatcher is not wired into the static
 * `Model::$dispatcher` field, so `static::saving($cb)` listeners
 * silently never fire. Validation runs synchronously on every save path
 * so a malformed row can never reach the DB regardless of how it was
 * constructed (Eloquent, raw query, or eager-load).
 *
 * The UNIQUE indexes on `user_id` and `group_id` are still authoritative
 * on MySQL (NULL ≠ NULL there); this class is the cross-engine truth.
 *
 * @property int      $id
 * @property string   $type          'user' | 'group'
 * @property int|null $user_id
 * @property int|null $group_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class Principal extends Model
{
    protected $table = 'principals';

    public const TYPE_USER  = 'user';
    public const TYPE_GROUP = 'group';

    /** @var list<string> */
    protected $fillable = ['type', 'user_id', 'group_id'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id'  => 'integer',
        'group_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Validate the XOR invariant before letting Eloquent persist the
     * row. Spora runs Eloquent standalone, so model events do not fire;
     * an override here is the only path that always runs.
     *
     * @param  array<string, mixed> $options
     */
    public function save(array $options = []): bool
    {
        $this->validateXor();
        return parent::save($options);
    }

    /**
     * Single source of truth for the principal-row invariants. Public so
     * the bulk insert path (used by migration 0067) can validate the
     * resulting rows in tests without round-tripping through Eloquent.
     *
     * @throws LogicException
     */
    public function validateXor(): void
    {
        $hasUser  = $this->user_id !== null;
        $hasGroup = $this->group_id !== null;
        if ($hasUser === $hasGroup) {
            throw new LogicException(
                'Principal must reference exactly one of user_id or group_id.',
            );
        }
        if ($hasUser && $this->type !== self::TYPE_USER) {
            throw new LogicException('Principal.type must be "user" when user_id is set.');
        }
        if ($hasGroup && $this->type !== self::TYPE_GROUP) {
            throw new LogicException('Principal.type must be "group" when group_id is set.');
        }
    }
}
