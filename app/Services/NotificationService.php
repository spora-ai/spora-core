<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Notification;
use Spora\Models\Task;

/**
 * Creates notification records and publishes real-time events via Mercure.
 */
class NotificationService
{
    public function __construct(
        private readonly MercurePublisherInterface $mercure,
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
        $this->mercure->publish($task->id, ['event' => 'pending_approval', 'task_id' => $task->id]);
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

    /**
     * @param array{user_id: int, type: string, title: string, body: string|null, data: array|null} $attributes
     */
    private function create(array $attributes): Notification
    {
        $notification = new Notification();
        $notification->fill($attributes);
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
            'created_at'=> $notification->created_at?->toIso8601String(),
        ];
    }
}