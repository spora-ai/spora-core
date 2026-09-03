<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Support\Carbon;
use Spora\Models\Notification;
use Spora\Models\Task;
use Spora\Models\User;

/**
 * Creates notification records and publishes real-time events via Mercure.
 */
class NotificationService implements NotificationServiceInterface
{
    public function __construct(
        private readonly MercurePublisherInterface $mercure,
        private readonly PrincipalResolver $principalResolver,
        private readonly ?SystemMailer $systemMailer = null,
        private readonly array $config = [],
        private readonly ?NotificationSubscriptionService $subscriptions = null,
    ) {}

    public function notifyTaskCompleted(Task $task): void
    {
        $rows = $this->fanOutForTask($task, [
            'type'  => 'task_completed',
            'title' => 'Task completed',
            'body'  => $task->user_prompt,
            'data'  => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        foreach ($rows as $userId => $notification) {
            $this->mercure->publishToUser(
                $userId,
                ['event' => 'notification', 'type' => 'task_completed', 'notification' => $this->toResource($notification)],
            );
        }

        // Task channel: principal-keyed so every group peer receives the
        // status update, not just the trigger user.
        $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), [
            'id'             => $task->id,
            'status'         => 'COMPLETED',
            'final_response' => $task->final_response,
            'step_count'     => $task->step_count,
        ]);
    }

    public function notifyTaskFailed(Task $task): void
    {
        $rows = $this->fanOutForTask($task, [
            'type'  => 'task_failed',
            'title' => 'Task failed',
            'body'  => $task->failure_reason ?: 'An error occurred during task execution.',
            'data'  => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        foreach ($rows as $userId => $notification) {
            $this->mercure->publishToUser(
                $userId,
                ['event' => 'notification', 'type' => 'task_failed', 'notification' => $this->toResource($notification)],
            );
        }

        $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), [
            'id'             => $task->id,
            'status'         => 'FAILED',
            'error_code'     => $task->error_code,
            'error_message'  => $task->error_message,
            'failure_reason' => $task->failure_reason,
            'step_count'     => $task->step_count,
        ]);
    }

    public function notifyPendingApproval(Task $task): void
    {
        $rows = $this->fanOutForTask($task, [
            'type'  => 'pending_approval',
            'title' => 'Task pending approval',
            'body'  => $task->user_prompt,
            'data'  => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        foreach ($rows as $userId => $notification) {
            $this->mercure->publishToUser(
                $userId,
                ['event' => 'notification', 'type' => 'pending_approval', 'notification' => $this->toResource($notification)],
            );
        }

        $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), [
            'event'   => 'pending_approval',
            'task_id' => $task->id,
        ]);
    }

    public function notifyScheduledRunCompleted(int $runId, Task $task): void
    {
        $rows = $this->fanOutForTask($task, [
            'type'  => 'scheduled_run_completed',
            'title' => 'Scheduled run completed',
            'body'  => $task->user_prompt,
            'data'  => ['run_id' => $runId, 'task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        foreach ($rows as $userId => $notification) {
            $this->mercure->publishToUser(
                $userId,
                ['event' => 'notification', 'type' => 'scheduled_run_completed', 'notification' => $this->toResource($notification)],
            );
        }
    }

    public function notifyTaskOrphaned(Task $task): void
    {
        $rows = $this->fanOutForTask($task, [
            'type'  => 'task_orphaned',
            'title' => 'Task interrupted',
            'body'  => 'The task was interrupted and has been stopped. You can retry it manually.',
            'data'  => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        foreach ($rows as $userId => $notification) {
            $this->mercure->publishToUser(
                $userId,
                ['event' => 'notification', 'type' => 'task_orphaned', 'notification' => $this->toResource($notification)],
            );
        }
    }

    public function notifyRetryQueued(Task $retryTask, int $attempt, int $max): void
    {
        $rows = $this->fanOutForTask($retryTask, [
            'type'  => 'task_retry_queued',
            'title' => 'Retry scheduled',
            'body'  => "Retry {$attempt}/{$max} scheduled.",
            'data'  => [
                'task_id'     => $retryTask->id,
                'agent_id'    => $retryTask->agent_id,
                'attempt'     => $attempt,
                'max'         => $max,
                'retry_after' => $retryTask->retry_after->toIso8601String(),
            ],
        ]);

        foreach ($rows as $userId => $notification) {
            $this->mercure->publishToUser(
                $userId,
                ['event' => 'notification', 'type' => 'task_retry_queued', 'notification' => $this->toResource($notification)],
            );
        }
    }

    public function notifyTaskRetrying(Task $task, int $attempt, int $max): void
    {
        $rows = $this->fanOutForTask($task, [
            'type'  => 'task_retrying',
            'title' => 'Retrying task',
            'body'  => "Retrying task (attempt {$attempt}/{$max})...",
            'data'  => [
                'task_id'  => $task->id,
                'agent_id' => $task->agent_id,
                'attempt'  => $attempt,
                'max'      => $max,
            ],
        ]);

        foreach ($rows as $userId => $notification) {
            $this->mercure->publishToUser(
                $userId,
                ['event' => 'notification', 'type' => 'task_retrying', 'notification' => $this->toResource($notification)],
            );
        }
    }

    /**
     * Insert one notification row per user that can act on this task's
     * principal. Returns a [userId => Notification] map so the caller can
     * publishToUser each row. Falls back to the trigger user when the
     * principal is unknown — keeps the prior behaviour for stale rows
     * where PrincipalResolver returns [].
     *
     * @param array{type: string, title: string, body: string|null, data: array|null} $payload
     * @return array<int, Notification>
     */
    private function fanOutForTask(Task $task, array $payload): array
    {
        $userIds = $this->principalResolver->visibleUserIds($task->principalOwnerId());
        if ($userIds === []) {
            $triggerUserId = $task->triggerUserId();
            if ($triggerUserId !== null) {
                $userIds = [$triggerUserId];
            }
        }

        $now  = Carbon::now();
        $rows = [];
        foreach ($userIds as $userId) {
            $notification = new Notification();
            $notification->fill([
                'user_id'    => $userId,
                'type'       => $payload['type'],
                'title'      => $payload['title'],
                'body'       => $payload['body'],
                'data'       => $payload['data'],
                'created_at' => $now,
            ]);
            $notification->save();
            $rows[$userId] = $notification;
        }

        return $rows;
    }

    public function sendEmailForScheduledRun(Task $task): void
    {
        // Default-on: feature ships enabled. Operators opt out via
        // SPORA_NOTIFICATIONS_EMAIL_ENABLED=false in .env.
        if ($this->systemMailer === null
            || $this->subscriptions === null
            || ! ($this->config['notifications']['email_enabled'] ?? true)
        ) {
            return;
        }

        // Recipients are resolved per-task from `notification_subscriptions`.
        // Replaces the previous `tasks.principal->user` routing, which
        // silently dropped emails for group-owned runs (a `type=group`
        // principal has `user_id IS NULL` per the XOR invariant in
        // `Principal::validateXor()`).
        $recipients = $this->subscriptions->resolveRecipientsForTask($task);
        if ($recipients === []) {
            return;
        }

        $users = User::query()->whereIn('id', $recipients)->get();
        if ($users->isEmpty()) {
            return;
        }

        /** @var \Spora\Models\Agent $agent */
        $agent = $task->agent;

        $variables = [
            'task_id'     => $task->id,
            'agent_name'  => $agent->name,
            'user_prompt' => $task->user_prompt,
            'site_name'   => $this->config['app_name'] ?? 'Spora',
            'run_url'     => $this->buildRunUrl($task->id),
        ];

        // One email per subscriber — keeps recipient addresses out of a
        // shared `To:` header.
        foreach ($users as $user) {
            /** @var User $user */
            $this->systemMailer->sendTemplatedEmail(
                'scheduled_run_completed',
                $variables,
                [$user->email],
            );
        }
    }

    private function buildRunUrl(int|string $taskId): string
    {
        $baseUrl = rtrim((string) ($this->config['app_url'] ?? ''), '/');
        $prefix  = \Spora\Core\RequestOrigin::normalizePrefix((string) ($this->config['app_prefix'] ?? ''));
        $sep     = $prefix === '' ? '' : '/';

        return $baseUrl . $prefix . $sep . 'tasks/' . $taskId;
    }

    private function toResource(Notification $notification): array
    {
        return [
            'id'        => $notification->id,
            'type'      => $notification->type,
            'title'     => $notification->title,
            'body'      => $notification->body,
            'data'      => $notification->data,
            'read_at'   => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{data: list<array>, pagination: array{total: int, per_page: int, current_page: int, last_page: int}}
     */
    public function getNotifications(int $userId, int $perPage, bool $unreadOnly): array
    {
        $query = Notification::where('user_id', $userId)->orderByDesc('created_at');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(fn(Notification $n) => $this->toResource($n))->all();

        return [
            'data' => $data,
            'pagination' => [
                'total'       => $paginator->total(),
                'per_page'    => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'   => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function markAsRead(int $notificationId, int $userId): ?array
    {
        $notification = Notification::where('id', $notificationId)->where('user_id', $userId)->first();

        if ($notification === null) {
            return null;
        }

        if ($notification->read_at === null) {
            $notification->read_at = Carbon::now();
            $notification->save();
        }

        return $this->toResource($notification);
    }

    public function markAllAsRead(int $userId): void
    {
        Notification::where('user_id', $userId)->whereNull('read_at')->update(['read_at' => Carbon::now()]);
    }

    public function deleteNotification(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)->where('user_id', $userId)->first();

        if ($notification === null) {
            return false;
        }

        $notification->delete();

        return true;
    }

    public function deleteAllForUser(int $userId): void
    {
        Notification::where('user_id', $userId)->delete();
    }
}
