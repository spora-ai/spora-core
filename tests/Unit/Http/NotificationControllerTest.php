<?php

declare(strict_types=1);

use Spora\Http\NotificationController;
use Spora\Models\Notification;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\HttpFoundation\Request;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

function makeNotificationControllerUnit(): array
{
    $authService = bootAuthLayer();

    $mercure = new class implements MercurePublisherInterface {
        public function publish(int $taskId, int $userId, array $taskData): bool
        {
            return true;
        }
        public function publishToUser(int $userId, array $data): bool
        {
            return true;
        }
    };

    $notificationService = new NotificationService($mercure);
    $controller = new NotificationController($authService, $notificationService);

    return [$controller, $authService, $notificationService];
}

function seedNotificationUserUnit(Spora\Auth\AuthService $authService, string $email = 'notif@example.com'): int
{
    static $seq = 0;
    $seq++;
    $userEmail = "{$seq}{$email}";
    $userId = $authService->register($userEmail, 'Password1!', 'Notif');
    simulateLoggedInSession($userId, $userEmail);
    return $userId;
}

function createNotificationForUnit(int $userId, string $type = 'task_completed', ?string $readAt = null): Notification
{
    return Notification::create([
        'user_id' => $userId,
        'type'    => $type,
        'title'   => 'A notification',
        'body'    => 'body',
        'data'    => ['k' => 'v'],
        'read_at' => $readAt,
    ]);
}

// index

test('index() returns paginated notifications with default per_page=20', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    $userId = seedNotificationUserUnit($authService);

    for ($i = 0; $i < 3; $i++) {
        createNotificationForUnit($userId, 'task_completed');
    }

    $request = Request::create('/api/v1/notifications', 'GET');
    $response = $controller->index($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['notifications'])->toHaveCount(3);
    expect($body['data']['pagination']['per_page'])->toBe(20);
});

test('index() clamps per_page to a max of 100', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    seedNotificationUserUnit($authService);

    $request = Request::create('/api/v1/notifications?per_page=500', 'GET');
    $response = $controller->index($request);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['pagination']['per_page'])->toBe(100);
});

test('index() falls back to per_page=20 when value is < 1', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    seedNotificationUserUnit($authService);

    $request = Request::create('/api/v1/notifications?per_page=0', 'GET');
    $response = $controller->index($request);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['pagination']['per_page'])->toBe(20);
});

test('index() with unread_only=true filters to unread notifications', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    $userId = seedNotificationUserUnit($authService);

    createNotificationForUnit($userId, 'task_completed');
    createNotificationForUnit($userId, 'task_completed', '2023-01-01 10:00:00');

    $request = Request::create('/api/v1/notifications?unread_only=true', 'GET');
    $response = $controller->index($request);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['notifications'])->toHaveCount(1);
    expect($body['data']['notifications'][0]['read_at'])->toBeNull();
});

// markRead

test('markRead() marks notification as read and returns it', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    $userId = seedNotificationUserUnit($authService);

    $notif = createNotificationForUnit($userId);

    $request = Request::create("/api/v1/notifications/{$notif->id}/read", 'POST');
    $request->attributes->set('id', $notif->id);
    $response = $controller->markRead($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['notification']['read_at'])->not->toBeNull();
});

test('markRead() returns 404 for unknown notification', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    seedNotificationUserUnit($authService);

    $request = Request::create('/api/v1/notifications/99999/read', 'POST');
    $request->attributes->set('id', 99999);
    $response = $controller->markRead($request);

    expect($response->getStatusCode())->toBe(404);
});

test('markRead() returns 404 for another user\'s notification', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    $userA = seedNotificationUserUnit($authService);
    $notif = createNotificationForUnit($userA);

    // Login as a different user
    seedNotificationUserUnit($authService, 'other@example.com');

    $request = Request::create("/api/v1/notifications/{$notif->id}/read", 'POST');
    $request->attributes->set('id', $notif->id);
    $response = $controller->markRead($request);

    expect($response->getStatusCode())->toBe(404);
});

// markAllRead

test('markAllRead() returns {marked: true} and marks all unread as read', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    $userId = seedNotificationUserUnit($authService);

    createNotificationForUnit($userId);
    createNotificationForUnit($userId);

    $response = $controller->markAllRead();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['marked'])->toBeTrue();

    expect(Notification::where('user_id', $userId)->whereNull('read_at')->count())->toBe(0);
});

// destroy

test('destroy() deletes notification and returns 200', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    $userId = seedNotificationUserUnit($authService);
    $notif = createNotificationForUnit($userId);

    $request = Request::create("/api/v1/notifications/{$notif->id}", 'DELETE');
    $request->attributes->set('id', $notif->id);
    $response = $controller->destroy($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Notification::find($notif->id))->toBeNull();
});

test('destroy() returns 404 for unknown notification', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    seedNotificationUserUnit($authService);

    $request = Request::create('/api/v1/notifications/99999', 'DELETE');
    $request->attributes->set('id', 99999);
    $response = $controller->destroy($request);

    expect($response->getStatusCode())->toBe(404);
});

// destroyAll

test('destroyAll() removes all notifications for the user', function (): void {
    [$controller, $authService] = makeNotificationControllerUnit();
    $userId = seedNotificationUserUnit($authService);

    createNotificationForUnit($userId);
    createNotificationForUnit($userId);

    $response = $controller->destroyAll();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBeTrue();
    expect(Notification::where('user_id', $userId)->count())->toBe(0);
});
