<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\Notification;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Tests\Support\TestCapturingMercure;

defined('NOTIF_TEST_PASSWORD') || define('NOTIF_TEST_PASSWORD', 'Password1!');

function makeNotificationServiceWithUser(): array
{
    $mercure = new TestCapturingMercure();
    $service = new NotificationService($mercure);

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "notif-svc-{$seq}@example.com", NOTIF_TEST_PASSWORD);

    return [$service, $mercure, $userId];
}

function makeTaskForUser(int $userId): Task
{
    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'NotifTestAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    return Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'Hello world',
        'final_response' => 'Hi back',
        'max_steps'   => 5,
        'step_count'  => 1,
    ]);
}

describe('NotificationService::getNotifications', function (): void {

    it('returns an empty paginated result for a new user', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        $result = $service->getNotifications($userId, 10, false);

        expect($result['data'])->toBe([]);
        expect($result['pagination']['total'])->toBe(0);
    });

    it('returns only the requested user’s notifications', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        $auth = bootAuthLayer();
        $otherUserId = bootAuth($auth, 'notif-other@example.com', NOTIF_TEST_PASSWORD);

        Notification::create(['user_id' => $userId,     'type' => 'task_completed', 'title' => 'Mine',   'created_at' => '2025-01-01 00:00:00']);
        Notification::create(['user_id' => $otherUserId, 'type' => 'task_completed', 'title' => 'Theirs', 'created_at' => '2025-01-02 00:00:00']);

        $result = $service->getNotifications($userId, 10, false);
        expect($result['data'])->toHaveCount(1);
        expect($result['data'][0]['title'])->toBe('Mine');
    });

    it('filters unread-only when requested', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'Read',   'read_at' => '2025-01-01 00:00:00']);
        Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'Unread', 'read_at' => null]);

        $result = $service->getNotifications($userId, 10, true);
        expect($result['data'])->toHaveCount(1);
        expect($result['data'][0]['title'])->toBe('Unread');
    });
});

describe('NotificationService::markAsRead', function (): void {

    it('returns null when the notification does not exist', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();
        expect($service->markAsRead(9999, $userId))->toBeNull();
    });

    it('returns null for another user’s notification', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        $auth = bootAuthLayer();
        $otherUserId = bootAuth($auth, 'notif-mark@example.com', NOTIF_TEST_PASSWORD);

        $n = Notification::create(['user_id' => $otherUserId, 'type' => 'task_completed', 'title' => 'Foreign']);

        expect($service->markAsRead($n->id, $userId))->toBeNull();
    });

    it('marks the notification as read and returns the resource', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        $n = Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'Read me']);

        $result = $service->markAsRead($n->id, $userId);
        expect($result)->not->toBeNull();
        expect($result['read_at'])->not->toBeNull();
    });
});

describe('NotificationService::markAllAsRead', function (): void {

    it('marks all of the user’s unread notifications as read', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'A', 'read_at' => null]);
        Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'B', 'read_at' => null]);

        $service->markAllAsRead($userId);

        $unread = Notification::where('user_id', $userId)->whereNull('read_at')->count();
        expect($unread)->toBe(0);
    });
});

describe('NotificationService::deleteNotification', function (): void {

    it('returns false when the notification does not exist', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();
        expect($service->deleteNotification(9999, $userId))->toBeFalse();
    });

    it('returns false for another user’s notification', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        $auth = bootAuthLayer();
        $otherUserId = bootAuth($auth, 'notif-del@example.com', NOTIF_TEST_PASSWORD);
        $n = Notification::create(['user_id' => $otherUserId, 'type' => 'task_completed', 'title' => 'Theirs']);

        expect($service->deleteNotification($n->id, $userId))->toBeFalse();
    });

    it('deletes the notification and returns true', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        $n = Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'Delete me']);
        expect($service->deleteNotification($n->id, $userId))->toBeTrue();
        expect(Notification::find($n->id))->toBeNull();
    });
});

describe('NotificationService::deleteAllForUser', function (): void {

    it('deletes only the requested user’s notifications', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();

        $auth = bootAuthLayer();
        $otherUserId = bootAuth($auth, 'notif-delall@example.com', NOTIF_TEST_PASSWORD);

        Notification::create(['user_id' => $userId,     'type' => 'task_completed', 'title' => 'Mine']);
        Notification::create(['user_id' => $otherUserId, 'type' => 'task_completed', 'title' => 'Theirs']);

        $service->deleteAllForUser($userId);

        expect(Notification::where('user_id', $userId)->count())->toBe(0);
        expect(Notification::where('user_id', $otherUserId)->count())->toBe(1);
    });
});

describe('NotificationService::notifyTaskCompleted', function (): void {

    it('creates a notification row and publishes to the user and task channels', function (): void {
        [$service, $mercure, $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        $service->notifyTaskCompleted($task);

        $notif = Notification::where('user_id', $userId)->first();
        expect($notif)->not->toBeNull();
        expect($notif->type)->toBe('task_completed');

        expect($mercure->userEvents)->toHaveCount(1);
        expect($mercure->userEvents[0]['data']['type'])->toBe('task_completed');

        expect($mercure->taskEvents)->toHaveCount(1);
        expect($mercure->taskEvents[0]['data']['status'])->toBe('COMPLETED');
    });
});

describe('NotificationService::notifyTaskFailed', function (): void {

    it('creates a task_failed notification and publishes the failure', function (): void {
        [$service, $mercure, $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);
        $task->failure_reason = 'Boom';
        $task->error_code = 'TIMEOUT';
        $task->error_message = 'Timed out';
        $task->save();

        $service->notifyTaskFailed($task);

        $notif = Notification::where('user_id', $userId)->where('type', 'task_failed')->first();
        expect($notif)->not->toBeNull();
        expect($notif->body)->toBe('Boom');

        expect($mercure->userEvents[0]['data']['type'])->toBe('task_failed');
        expect($mercure->taskEvents[0]['data']['status'])->toBe('FAILED');
        expect($mercure->taskEvents[0]['data']['error_code'])->toBe('TIMEOUT');
    });
});

describe('NotificationService::notifyPendingApproval', function (): void {

    it('creates a pending_approval notification and publishes to both channels', function (): void {
        [$service, $mercure, $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        $service->notifyPendingApproval($task);

        $notif = Notification::where('user_id', $userId)->where('type', 'pending_approval')->first();
        expect($notif)->not->toBeNull();

        expect($mercure->userEvents[0]['data']['type'])->toBe('pending_approval');
        expect($mercure->taskEvents[0]['data']['event'])->toBe('pending_approval');
    });
});

describe('NotificationService::notifyTaskOrphaned', function (): void {

    it('creates a task_orphaned notification and publishes to the user channel', function (): void {
        [$service, $mercure, $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        $service->notifyTaskOrphaned($task);

        $notif = Notification::where('user_id', $userId)->where('type', 'task_orphaned')->first();
        expect($notif)->not->toBeNull();
        expect($mercure->userEvents[0]['data']['type'])->toBe('task_orphaned');
    });
});
