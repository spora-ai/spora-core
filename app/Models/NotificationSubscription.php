<?php

declare(strict_types=1);

namespace Spora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (user, target) opt-in to scheduled-run email notifications.
 *
 * `target_id` resolution: `target_type = 'agent'` → `agents.id`;
 * `target_type = 'principal'` → `principals.id` (mirrors
 * `Agent.principal_id`). The dispatch path
 * ({@see \Spora\Services\NotificationSubscriptionService::resolveRecipientsForTask()})
 * unions subscribers on each side.
 *
 * @property int                $id
 * @property int                $user_id
 * @property string             $target_type    {@see self::TARGET_AGENT} | {@see self::TARGET_PRINCIPAL}
 * @property int                $target_id
 * @property \Carbon\Carbon     $created_at
 * @property \Carbon\Carbon     $updated_at
 */
final class NotificationSubscription extends Model
{
    protected $table = 'notification_subscriptions';

    public const TARGET_AGENT     = 'agent';
    public const TARGET_PRINCIPAL = 'principal';

    public $timestamps = true;

    /** @var list<string> */
    protected $fillable = ['user_id', 'target_type', 'target_id'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id'   => 'integer',
        'target_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
