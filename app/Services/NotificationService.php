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
        private readonly ?SystemMailer $systemMailer = null,
        private readonly array $config = [],
    ) {}

    public function notifyTaskCompleted(Task $task): void
    {
        $notification = $this->create([
            'user_id' => $task->user_id,
            'type'    => 'task_completed',
            'title'   => 'Task completed',
            'body'    => $task->user_prompt,
            'data'    => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        $this->mercure->publishToUser(
            $task->user_id,
            ['event' => 'notification', 'type' => 'task_completed', 'notification' => $this->toResource($notification)],
        );

        // Also publish to the task channel so the UI updates the task status
        $this->mercure->publish($task->id, $task->user_id, [
            'id'             => $task->id,
            'status'         => 'COMPLETED',
            'final_response' => $task->final_response,
            'step_count'     => $task->step_count,
        ]);
    }

    public function notifyTaskFailed(Task $task): void
    {
        $notification = $this->create([
            'user_id' => $task->user_id,
            'type'    => 'task_failed',
            'title'   => 'Task failed',
            'body'    => $task->failure_reason ?: 'An error occurred during task execution.',
            'data'    => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        $this->mercure->publishToUser(
            $task->user_id,
            ['event' => 'notification', 'type' => 'task_failed', 'notification' => $this->toResource($notification)],
        );

        // Also publish to the task channel so the UI updates the task status
        $this->mercure->publish($task->id, $task->user_id, [
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
        $notification = $this->create([
            'user_id' => $task->user_id,
            'type'    => 'pending_approval',
            'title'   => 'Task pending approval',
            'body'    => $task->user_prompt,
            'data'    => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        // Publish to user channel for notification badge updates
        $this->mercure->publishToUser(
            $task->user_id,
            ['event' => 'notification', 'type' => 'pending_approval', 'notification' => $this->toResource($notification)],
        );

        // Publish to task channel so the UI can update task status in real-time
        $this->mercure->publish($task->id, $task->user_id, ['event' => 'pending_approval', 'task_id' => $task->id]);
    }

    public function notifyScheduledRunCompleted(int $runId, Task $task): void
    {
        $notification = $this->create([
            'user_id' => $task->user_id,
            'type'    => 'scheduled_run_completed',
            'title'   => 'Scheduled run completed',
            'body'    => $task->user_prompt,
            'data'    => ['run_id' => $runId, 'task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        $this->mercure->publishToUser(
            $task->user_id,
            ['event' => 'notification', 'type' => 'scheduled_run_completed', 'notification' => $this->toResource($notification)],
        );
    }

    public function notifyTaskOrphaned(Task $task): void
    {
        $notification = $this->create([
            'user_id' => $task->user_id,
            'type'    => 'task_orphaned',
            'title'   => 'Task interrupted',
            'body'    => 'The task was interrupted and has been stopped. You can retry it manually.',
            'data'    => ['task_id' => $task->id, 'agent_id' => $task->agent_id],
        ]);

        $this->mercure->publishToUser(
            $task->user_id,
            ['event' => 'notification', 'type' => 'task_orphaned', 'notification' => $this->toResource($notification)],
        );
    }

    public function notifyRetryQueued(Task $retryTask, int $attempt, int $max): void
    {
        $notification = $this->create([
            'user_id' => $retryTask->user_id,
            'type'    => 'task_retry_queued',
            'title'   => 'Retry scheduled',
            'body'    => "Retry {$attempt}/{$max} scheduled.",
            'data'    => [
                'task_id'      => $retryTask->id,
                'agent_id'     => $retryTask->agent_id,
                'attempt'      => $attempt,
                'max'          => $max,
                'retry_after'  => $retryTask->retry_after->toIso8601String(),
            ],
        ]);

        $this->mercure->publishToUser(
            $retryTask->user_id,
            ['event' => 'notification', 'type' => 'task_retry_queued', 'notification' => $this->toResource($notification)],
        );
    }

    public function notifyTaskRetrying(Task $task, int $attempt, int $max): void
    {
        $notification = $this->create([
            'user_id' => $task->user_id,
            'type'    => 'task_retrying',
            'title'   => 'Retrying task',
            'body'    => "Retrying task (attempt {$attempt}/{$max})...",
            'data'    => [
                'task_id' => $task->id,
                'agent_id' => $task->agent_id,
                'attempt'  => $attempt,
                'max'      => $max,
            ],
        ]);

        $this->mercure->publishToUser(
            $task->user_id,
            ['event' => 'notification', 'type' => 'task_retrying', 'notification' => $this->toResource($notification)],
        );
    }

    public function sendEmailForScheduledRun(Task $task): void
    {
        if (! ($this->config['notifications']['email_enabled'] ?? false)) {
            return;
        }

        if ($this->systemMailer === null) {
            return;
        }

        $user = $task->user;
        if ($user === null) {
            return;
        }

        /** @var User $user */
        $agent = $task->agent;

        $this->systemMailer->sendTemplatedEmail(
            'scheduled_run_completed',
            [
                'task_id'     => $task->id,
                'agent_name'  => $agent->name,
                'user_prompt' => $task->user_prompt,
                'site_name'   => $this->config['app_name'] ?? 'Spora',
            ],
            [$user->email],
        );
    }

    /**
     * @param array{user_id: int, type: string, title: string, body: string|null, data: array|null} $attributes
     */
    private function create(array $attributes): Notification
    {
        $notification = new Notification();
        $notification->fill($attributes);
        $notification->created_at = Carbon::now();
        $notification->save();

        return $notification;
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

    // ── CRUD ─────────────────────────────────────────────────────────────────

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
