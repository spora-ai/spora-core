<?php

declare(strict_types=1);

use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\NotificationController;
use Spora\Models\Agent;
use Spora\Models\Notification;
use Spora\Models\Task;
use Spora\Security\CsrfTokenService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');
defined('TEST_TIMESTAMP_FORMAT') || define('TEST_TIMESTAMP_FORMAT', 'Y-m-d H:i:s');
const NOTIFICATIONS_API_PATH = '/api/v1/notifications/';

function makeNotificationService(?MercurePublisherInterface $mercureOverride = null): NotificationService
{
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = $mercureOverride ?? Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);
    $mercure->allows('publishToUser')->andReturn(true);

    return new NotificationService($mercure);
}

function makeNotificationController(): array
{
    $authService = bootAuthLayer();
    $notificationService = makeNotificationService();
    $controller = new NotificationController($authService, $notificationService);
    $authMiddleware = new AuthMiddleware($authService);
    $csrfService = new CsrfTokenService();
    $csrfMiddleware = new CsrfMiddleware($csrfService);

    return [$controller, $authService, $authMiddleware, $csrfMiddleware];
}

function seedUserAndAgentForNotification(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('notify@example.com', TEST_PASSWORD, 'Notify');
    simulateLoggedInSession($userId, 'notify@example.com');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'NotifTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent, $authService];
}

describe('NotificationService', function (): void {
    it('notifyTaskCompleted creates a task_completed notification and publishes to Mercure', function (): void {
        [$userId, $agent] = seedUserAndAgentForNotification();

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publishToUser')
            ->once()
            ->with($userId, Mockery::type('array'))
            ->andReturn(true);
        $mercure->shouldReceive('publish')
            ->once()
            ->with(Mockery::type('int'), Mockery::type('int'), Mockery::type('array'))
            ->andReturn(true);

        $service = new NotificationService($mercure);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $userId,
            'status'      => 'COMPLETED',
            'user_prompt' => 'Hello',
            'max_steps'   => 10,
        ]);

        $service->notifyTaskCompleted($task);

        $notif = Notification::where('user_id', $userId)->first();
        expect($notif)->not->toBeNull()
            ->and($notif->type)->toBe('task_completed')
            ->and($notif->title)->toBe('Task completed')
            ->and($notif->body)->toBe('Hello')
            ->and($notif->read_at)->toBeNull()
            ->and($notif->data['task_id'])->toBe($task->id)
            ->and($notif->data['agent_id'])->toBe($agent->id);
    });

    it('notifyTaskFailed creates a task_failed notification', function (): void {
        [$userId, $agent] = seedUserAndAgentForNotification();

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->allows('publishToUser')->andReturn(true);
        $mercure->allows('publish')->andReturn(true);

        $service = new NotificationService($mercure);

        $task = Task::create([
            'agent_id'       => $agent->id,
            'user_id'        => $userId,
            'status'         => 'FAILED',
            'user_prompt'    => 'Fail me',
            'max_steps'      => 10,
            'failure_reason' => 'Intentional failure',
        ]);

        $service->notifyTaskFailed($task);

        $notif = Notification::where('user_id', $userId)->first();
        expect($notif)->not->toBeNull()
            ->and($notif->type)->toBe('task_failed')
            ->and($notif->body)->toContain('Intentional failure');
    });

    it('notifyPendingApproval publishes to both user channel and task channel', function (): void {
        [$userId, $agent] = seedUserAndAgentForNotification();

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldReceive('publishToUser')
            ->once()
            ->with($userId, Mockery::type('array'))
            ->andReturn(true);
        $mercure->shouldReceive('publish')
            ->once()
            ->with(Mockery::type('int'), Mockery::type('int'), Mockery::type('array'))
            ->andReturn(true);

        $service = new NotificationService($mercure);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $userId,
            'status'      => 'PENDING_APPROVAL',
            'user_prompt' => 'Approve me',
            'max_steps'   => 10,
        ]);

        $service->notifyPendingApproval($task);

        $notif = Notification::where('user_id', $userId)->first();
        expect($notif)->not->toBeNull()
            ->and($notif->type)->toBe('pending_approval')
            ->and($notif->data['task_id'])->toBe($task->id);
    });

    it('notifyScheduledRunCompleted creates a scheduled_run_completed notification', function (): void {
        [$userId, $agent] = seedUserAndAgentForNotification();

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->allows('publishToUser')->andReturn(true);

        $service = new NotificationService($mercure);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $userId,
            'status'      => 'COMPLETED',
            'user_prompt' => 'Scheduled task',
            'max_steps'   => 10,
        ]);

        $service->notifyScheduledRunCompleted(42, $task);

        $notif = Notification::where('user_id', $userId)->first();
        expect($notif)->not->toBeNull()
            ->and($notif->type)->toBe('scheduled_run_completed')
            ->and($notif->data['run_id'])->toBe(42)
            ->and($notif->data['task_id'])->toBe($task->id);
    });

    it('notifications are ordered newest first', function (): void {
        [$userId] = seedUserAndAgentForNotification();

        $older = Notification::create([
            'user_id'    => $userId,
            'type'       => 'task_completed',
            'title'      => 'First',
            'created_at' => date(TEST_TIMESTAMP_FORMAT, strtotime('-2 hours')),
        ]);
        $newer = Notification::create([
            'user_id'    => $userId,
            'type'       => 'task_failed',
            'title'      => 'Second',
            'created_at' => date(TEST_TIMESTAMP_FORMAT, strtotime('-1 hour')),
        ]);

        $all = Notification::where('user_id', $userId)->orderByDesc('created_at')->get();

        expect($all[0]->id)->toBe($newer->id)
            ->and($all[1]->id)->toBe($older->id);
    });
});

describe('NotificationController', function (): void {
    it('index returns paginated notifications for the logged-in user', function (): void {
        [$userId, , $authService] = seedUserAndAgentForNotification();

        Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'Notif 1']);
        Notification::create(['user_id' => $userId, 'type' => 'task_failed', 'title' => 'Notif 2']);

        $controller = new NotificationController($authService, makeNotificationService());
        $request = jsonRequest('GET', '/api/v1/notifications');
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['notifications'])->toHaveCount(2)
            ->and($body['data']['pagination']['total'])->toBe(2);
    });

    it('index returns only unread notifications with unread_only=true', function (): void {
        [$userId] = seedUserAndAgentForNotification();

        Notification::create([
            'user_id' => $userId, 'type' => 'task_completed', 'title' => 'Read',
            'read_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);
        Notification::create([
            'user_id' => $userId, 'type' => 'task_completed', 'title' => 'Unread',
        ]);

        $controller = new NotificationController(bootAuthLayer(), makeNotificationService());
        $request = jsonRequest('GET', '/api/v1/notifications?unread_only=true');
        $response = $controller->index($request);

        $body = json_decode($response->getContent(), true);
        expect($body['data']['notifications'])->toHaveCount(1)
            ->and($body['data']['notifications'][0]['title'])->toBe('Unread');
    });

    it('markRead marks a notification as read', function (): void {
        [$userId] = seedUserAndAgentForNotification();

        $notif = Notification::create([
            'user_id' => $userId, 'type' => 'task_completed', 'title' => 'To Read',
        ]);
        expect($notif->read_at)->toBeNull();

        $controller = new NotificationController(bootAuthLayer(), makeNotificationService());
        $request = jsonRequest('POST', NOTIFICATIONS_API_PATH . $notif->id . '/read');
        $request->attributes->set('id', $notif->id);
        $response = $controller->markRead($request);

        expect($response->getStatusCode())->toBe(200);
        $notif->refresh();
        expect($notif->read_at)->not->toBeNull();
    });

    it('markRead returns 404 for notification belonging to another user', function (): void {
        seedUserAndAgentForNotification();
        $otherUserId = bootAuthLayer()->register('other@example.com', TEST_PASSWORD, 'Other');

        $notif = Notification::create([
            'user_id' => $otherUserId, 'type' => 'task_completed', 'title' => 'Other',
        ]);

        $controller = new NotificationController(bootAuthLayer(), makeNotificationService());
        $request = jsonRequest('POST', NOTIFICATIONS_API_PATH . $notif->id . '/read');
        $response = $controller->markRead($request);

        expect($response->getStatusCode())->toBe(404);
    });

    it('markAllRead marks all unread notifications as read', function (): void {
        [$userId] = seedUserAndAgentForNotification();

        Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'A']);
        Notification::create(['user_id' => $userId, 'type' => 'task_completed', 'title' => 'B']);

        $controller = new NotificationController(bootAuthLayer(), makeNotificationService());
        $response = $controller->markAllRead();

        expect($response->getStatusCode())->toBe(200);
        $unread = Notification::where('user_id', $userId)->whereNull('read_at')->count();
        expect($unread)->toBe(0);
    });

    it('destroy deletes a notification and returns 204 with no body', function (): void {
        [$userId] = seedUserAndAgentForNotification();

        $notif = Notification::create([
            'user_id' => $userId, 'type' => 'task_completed', 'title' => 'To Delete',
        ]);

        $controller = new NotificationController(bootAuthLayer(), makeNotificationService());
        $request = jsonRequest('DELETE', NOTIFICATIONS_API_PATH . $notif->id);
        $request->attributes->set('id', $notif->id);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBe(true);
        expect(Notification::find($notif->id))->toBeNull();
    });

    it('destroy returns 404 for notification belonging to another user', function (): void {
        seedUserAndAgentForNotification();
        $otherUserId = bootAuthLayer()->register('other@example.com', TEST_PASSWORD, 'Other');

        $notif = Notification::create([
            'user_id' => $otherUserId, 'type' => 'task_completed', 'title' => 'Other',
        ]);

        $controller = new NotificationController(bootAuthLayer(), makeNotificationService());
        $request = jsonRequest('DELETE', NOTIFICATIONS_API_PATH . $notif->id);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(404);
        expect(Notification::find($notif->id))->not->toBeNull();
    });
});
