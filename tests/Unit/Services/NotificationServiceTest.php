<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Spora\Models\Agent;
use Spora\Models\MailTemplate;
use Spora\Models\Notification;
use Spora\Models\Task;
use Spora\Models\User;
use Spora\Services\NotificationService;
use Spora\Services\SystemMailer;
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

describe('NotificationService::notifyScheduledRunCompleted', function (): void {

    it('creates a scheduled_run_completed notification with run_id and publishes to the user channel', function (): void {
        [$service, $mercure, $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        $service->notifyScheduledRunCompleted(42, $task);

        $notif = Notification::where('user_id', $userId)->where('type', 'scheduled_run_completed')->first();
        expect($notif)->not->toBeNull()
            ->and($notif->title)->toBe('Scheduled run completed')
            ->and($notif->data['run_id'])->toBe(42)
            ->and($notif->data['task_id'])->toBe($task->id)
            ->and($notif->data['agent_id'])->toBe($task->agent_id);

        expect($mercure->userEvents)->toHaveCount(1);
        expect($mercure->userEvents[0]['data']['type'])->toBe('scheduled_run_completed');
        expect($mercure->userEvents[0]['data']['notification']['type'])->toBe('scheduled_run_completed');
        // Scheduled run notifications only publish to the user channel, not the task channel
        expect($mercure->taskEvents)->toBe([]);
    });
});

describe('NotificationService::notifyRetryQueued', function (): void {

    it('creates a task_retry_queued notification with attempt/max/retry_after and publishes to the user channel', function (): void {
        [$service, $mercure, $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);
        $retryAt = Carbon::now()->addMinutes(5);
        $task->retry_after = $retryAt;
        $task->save();

        $service->notifyRetryQueued($task, 1, 3);

        $notif = Notification::where('user_id', $userId)->where('type', 'task_retry_queued')->first();
        expect($notif)->not->toBeNull()
            ->and($notif->title)->toBe('Retry scheduled')
            ->and($notif->body)->toBe('Retry 1/3 scheduled.')
            ->and($notif->data['task_id'])->toBe($task->id)
            ->and($notif->data['agent_id'])->toBe($task->agent_id)
            ->and($notif->data['attempt'])->toBe(1)
            ->and($notif->data['max'])->toBe(3)
            ->and($notif->data['retry_after'])->toBe($retryAt->toIso8601String());

        expect($mercure->userEvents)->toHaveCount(1);
        expect($mercure->userEvents[0]['data']['type'])->toBe('task_retry_queued');
        expect($mercure->taskEvents)->toBe([]);
    });
});

describe('NotificationService::notifyTaskRetrying', function (): void {

    it('creates a task_retrying notification with attempt/max and publishes to the user channel', function (): void {
        [$service, $mercure, $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        $service->notifyTaskRetrying($task, 2, 3);

        $notif = Notification::where('user_id', $userId)->where('type', 'task_retrying')->first();
        expect($notif)->not->toBeNull()
            ->and($notif->title)->toBe('Retrying task')
            ->and($notif->body)->toBe('Retrying task (attempt 2/3)...')
            ->and($notif->data['task_id'])->toBe($task->id)
            ->and($notif->data['agent_id'])->toBe($task->agent_id)
            ->and($notif->data['attempt'])->toBe(2)
            ->and($notif->data['max'])->toBe(3);

        expect($mercure->userEvents)->toHaveCount(1);
        expect($mercure->userEvents[0]['data']['type'])->toBe('task_retrying');
        expect($mercure->taskEvents)->toBe([]);
    });
});

describe('NotificationService::sendEmailForScheduledRun', function (): void {

    it('returns silently when notifications.email_enabled is false in config', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        $testHandler = new TestHandler();
        $logger = new Logger('test', [$testHandler]);
        $systemMailer = new SystemMailer(['mail_driver' => 'log'], $logger);

        $service = new NotificationService(new TestCapturingMercure(), $systemMailer, [
            'notifications' => ['email_enabled' => false],
            'app_name'      => 'Spora',
        ]);

        $service->sendEmailForScheduledRun($task);

        expect($testHandler->getRecords())->toBe([]);
    });

    it('returns silently when systemMailer is null', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        $service = new NotificationService(new TestCapturingMercure(), null, [
            'notifications' => ['email_enabled' => true],
            'app_name'      => 'Spora',
        ]);

        $service->sendEmailForScheduledRun($task);

        // No exception and no notification created (different method)
        expect(Notification::where('user_id', $userId)->count())->toBe(0);
    });

    it('sends the scheduled_run_completed email when enabled and the template exists', function (): void {
        [$service, , $userId] = makeNotificationServiceWithUser();
        $task = makeTaskForUser($userId);

        MailTemplate::create([
            'name'      => 'scheduled_run_completed',
            'subject'   => 'Run completed: {{agent_name}}',
            'body_text' => 'Agent {{agent_name}} finished task {{task_id}} (prompt: {{user_prompt}}).',
            'body_html' => null,
        ]);

        $testHandler = new TestHandler();
        $logger = new Logger('test', [$testHandler]);
        $systemMailer = new SystemMailer(['mail_driver' => 'log'], $logger);

        $service = new NotificationService(new TestCapturingMercure(), $systemMailer, [
            'notifications' => ['email_enabled' => true],
            'app_name'      => 'Spora',
        ]);

        $service->sendEmailForScheduledRun($task);

        $user = User::find($userId);

        expect($testHandler->hasInfoThatContains('Mail sent via log driver'))->toBeTrue();

        $records = $testHandler->getRecords();
        $context = $records[0]->context;
        expect($context['to'])->toContain($user->email);
        expect($context['subject'])->toBe('Run completed: NotifTestAgent');
    });
});
