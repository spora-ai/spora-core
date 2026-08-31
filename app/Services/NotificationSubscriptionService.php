<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PDOException;
use Spora\Models\GroupMembership;
use Spora\Models\NotificationSubscription;
use Spora\Models\Principal;
use Spora\Models\Task;

/**
 * Per-user opt-in registry for scheduled-run email notifications.
 *
 * Replaces the previous `tasks.principal->user` routing, which silently
 * dropped emails for group-owned scheduled runs because a `type=group`
 * principal has `user_id IS NULL` (the XOR invariant in
 * {@see Principal::validateXor()}). The new model also makes
 * "unsubscribe" a first-class state instead of something the immutable
 * `trigger_user_id` column structurally couldn't express.
 *
 * Defaults are seeded lazily on the first dispatch for an agent that
 * has zero subscribers: subscribe the principal's user (or every
 * current group member) so the first scheduled run dispatches somewhere.
 * Lazy seeding avoids wiring Eloquent model events — Spora runs
 * Eloquent standalone without a wired event dispatcher; see
 * {@see Principal::save()} for the workaround pattern.
 */
final class NotificationSubscriptionService
{
    public function resolveRecipientsForTask(Task $task): array
    {
        $this->ensureDefaultSubscribersExist($task);

        $rows = Capsule::table('notification_subscriptions')
            ->where(function ($q) use ($task): void {
                $q->where(static function ($q2) use ($task): void {
                    $q2->where('target_type', NotificationSubscription::TARGET_AGENT)
                        ->where('target_id', (int) $task->agent_id);
                })->orWhere(static function ($q2) use ($task): void {
                    $q2->where('target_type', NotificationSubscription::TARGET_PRINCIPAL)
                        ->where('target_id', (int) $task->principal_id);
                });
            })
            ->distinct()
            ->pluck('user_id')
            ->all();

        $ids = array_values(array_unique(array_map('intval', $rows)));

        if ($ids === []) {
            return [];
        }

        return Capsule::table('users')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn($v) => (int) $v)
            ->all();
    }

    /**
     * If the task's agent or principal has zero subscribers, seed defaults:
     * the principal's user (user-principal) or every current group member
     * (group-principal) is auto-subscribed to the principal. Idempotent —
     * safe to call on every dispatch.
     */
    public function ensureDefaultSubscribersExist(Task $task): void
    {
        $hasAny = NotificationSubscription::query()
            ->where(static function ($q) use ($task): void {
                $q->where(static function ($q2) use ($task): void {
                    $q2->where('target_type', NotificationSubscription::TARGET_AGENT)
                        ->where('target_id', (int) $task->agent_id);
                })->orWhere(static function ($q2) use ($task): void {
                    $q2->where('target_type', NotificationSubscription::TARGET_PRINCIPAL)
                        ->where('target_id', (int) $task->principal_id);
                });
            })
            ->exists();

        if ($hasAny) {
            return;
        }

        $principal = Principal::find((int) $task->principal_id);
        if ($principal === null) {
            return;
        }

        $userIds = $this->defaultUserIdsForPrincipal($principal);
        if ($userIds === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($userIds as $userId) {
            $this->insertIgnore([
                'user_id'     => $userId,
                'target_type' => NotificationSubscription::TARGET_PRINCIPAL,
                'target_id'   => (int) $task->principal_id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function subscribeUserToTarget(int $userId, string $targetType, int $targetId): void
    {
        $this->assertTargetType($targetType);
        $this->insertIgnore([
            'user_id'     => $userId,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function unsubscribeUserFromTarget(int $userId, string $targetType, int $targetId): void
    {
        $this->assertTargetType($targetType);
        NotificationSubscription::query()
            ->where('user_id', $userId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->delete();
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    public function getSubscriptionsForUser(int $userId): Collection
    {
        return NotificationSubscription::query()
            ->where('user_id', $userId)
            ->orderBy('target_type')
            ->orderBy('target_id')
            ->get();
    }

    /**
     * @return list<int>
     */
    private function defaultUserIdsForPrincipal(Principal $principal): array
    {
        if ($principal->type === Principal::TYPE_USER) {
            return $principal->user_id !== null ? [(int) $principal->user_id] : [];
        }

        if ($principal->type === Principal::TYPE_GROUP && $principal->group_id !== null) {
            return GroupMembership::query()
                ->where('group_id', (int) $principal->group_id)
                ->pluck('user_id')
                ->map(static fn($v) => (int) $v)
                ->all();
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertIgnore(array $row): void
    {
        try {
            NotificationSubscription::query()->create($row);
        } catch (PDOException) {
            // Unique index on (user_id, target_type, target_id) — a parallel
            // seeder beat us to it. Safe to ignore; the row is the same.
        }
    }

    private function assertTargetType(string $targetType): void
    {
        if (!in_array($targetType, [
            NotificationSubscription::TARGET_AGENT,
            NotificationSubscription::TARGET_PRINCIPAL,
        ], true)) {
            throw new InvalidArgumentException("Unknown notification target_type: {$targetType}");
        }
    }
}
