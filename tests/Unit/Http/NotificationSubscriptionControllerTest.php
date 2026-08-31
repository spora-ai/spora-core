<?php

declare(strict_types=1);

use Spora\Http\NotificationSubscriptionController;
use Spora\Services\NotificationSubscriptionService;
use Symfony\Component\HttpFoundation\Request;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

function makeSubscriptionControllerUnit(array $configOverrides = []): array
{
    $authService = bootAuthLayer();
    $subscriptions = new NotificationSubscriptionService();
    $config = array_replace_recursive(
        ['notifications' => ['email_enabled' => true]],
        $configOverrides,
    );
    $controller = new NotificationSubscriptionController($authService, $subscriptions, $config);

    return [$controller, $authService, $subscriptions];
}

function seedSubscriptionUserUnit(Spora\Auth\AuthService $authService, string $email = 'sub@example.com'): int
{
    static $seq = 0;
    $seq++;
    $userEmail = "{$seq}{$email}";
    $userId = $authService->register($userEmail, 'Password1!', 'SubUser');
    simulateLoggedInSession($userId, $userEmail);

    return $userId;
}

// index

test('index() returns an empty list when the user has no subscriptions', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['subscriptions'])->toBe([]);
});

test('index() returns every subscription the user holds', function (): void {
    [$controller, $authService, $subscriptions] = makeSubscriptionControllerUnit();
    $userId = seedSubscriptionUserUnit($authService);

    $subscriptions->subscribeUserToTarget($userId, Spora\Models\NotificationSubscription::TARGET_PRINCIPAL, 12);
    $subscriptions->subscribeUserToTarget($userId, Spora\Models\NotificationSubscription::TARGET_AGENT, 42);

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['subscriptions'])->toHaveCount(2);
    expect(array_column($body['data']['subscriptions'], 'target_id'))->toEqualCanonicalizing([12, 42]);
});

test('index() includes the caller\'s user_principal_id so the SPA can render the "My personal agents" row', function (): void {
    [$controller, $authService, $subscriptions] = makeSubscriptionControllerUnit();
    $userId = seedSubscriptionUserUnit($authService);

    // bootAuth (via seedSubscriptionUserUnit) doesn't materialise a
    // user-principal — the real sign-in path does, via AuthWorkflow.
    $userPrincipalId = createUserPrincipalPublic($userId);
    expect($userPrincipalId)->toBeGreaterThan(0);

    $response = $controller->index();
    $body = json_decode($response->getContent(), true);
    expect($body['data']['user_principal_id'])->toBe($userPrincipalId);
});

test('index() returns null user_principal_id when the user has no user-principal row yet', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    // Wipe the user-principal so the controller has nothing to resolve.
    Spora\Models\Principal::query()
        ->where('type', Spora\Models\Principal::TYPE_USER)
        ->delete();

    $response = $controller->index();
    $body = json_decode($response->getContent(), true);
    expect($body['data']['user_principal_id'])->toBeNull();
});

test('index() reports email_enabled=true by default so the SPA renders no banner', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $response = $controller->index();
    $body = json_decode($response->getContent(), true);
    expect($body['data']['email_enabled'])->toBeTrue();
});

test('index() reports email_enabled=false when the operator opted out via config', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit([
        'notifications' => ['email_enabled' => false],
    ]);
    seedSubscriptionUserUnit($authService);

    $response = $controller->index();
    $body = json_decode($response->getContent(), true);
    expect($body['data']['email_enabled'])->toBeFalse();
});

// subscribe

test('subscribe() creates a new subscription and returns 201', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $request = Request::create(
        '/api/v1/notifications/subscriptions',
        'POST',
        content: json_encode(['target_type' => 'principal', 'target_id' => 7]),
    );

    $response = $controller->subscribe($request);

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['subscribed'])->toBeTrue();
    expect(Spora\Models\NotificationSubscription::query()->where('target_type', 'principal')->where('target_id', 7)->count())->toBe(1);
});

test('subscribe() is idempotent — repeat calls do not duplicate the row', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $payload = json_encode(['target_type' => 'agent', 'target_id' => 5]);
    $controller->subscribe(Request::create('/api/v1/notifications/subscriptions', 'POST', content: $payload));
    $controller->subscribe(Request::create('/api/v1/notifications/subscriptions', 'POST', content: $payload));

    expect(Spora\Models\NotificationSubscription::query()->where('target_type', 'agent')->where('target_id', 5)->count())->toBe(1);
});

test('subscribe() returns 400 when target_type is invalid', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $request = Request::create(
        '/api/v1/notifications/subscriptions',
        'POST',
        content: json_encode(['target_type' => 'banana', 'target_id' => 1]),
    );

    $response = $controller->subscribe($request);
    expect($response->getStatusCode())->toBe(400);
});

test('subscribe() returns 400 when target_id is missing or non-positive', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $request = Request::create(
        '/api/v1/notifications/subscriptions',
        'POST',
        content: json_encode(['target_type' => 'agent', 'target_id' => 0]),
    );

    $response = $controller->subscribe($request);
    expect($response->getStatusCode())->toBe(400);
});

test('subscribe() returns 400 when the body is not JSON', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $request = Request::create(
        '/api/v1/notifications/subscriptions',
        'POST',
        content: 'not json',
    );

    $response = $controller->subscribe($request);
    expect($response->getStatusCode())->toBe(400);
});

// unsubscribe

test('unsubscribe() removes the subscription and returns 200', function (): void {
    [$controller, $authService, $subscriptions] = makeSubscriptionControllerUnit();
    $userId = seedSubscriptionUserUnit($authService);

    $subscriptions->subscribeUserToTarget($userId, Spora\Models\NotificationSubscription::TARGET_PRINCIPAL, 9);

    $request = Request::create(
        '/api/v1/notifications/subscriptions',
        'DELETE',
        content: json_encode(['target_type' => 'principal', 'target_id' => 9]),
    );

    $response = $controller->unsubscribe($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['unsubscribed'])->toBeTrue();
    expect(Spora\Models\NotificationSubscription::query()->where('target_type', 'principal')->where('target_id', 9)->count())->toBe(0);
});

test('unsubscribe() is idempotent — repeat calls succeed even when the row is gone', function (): void {
    [$controller, $authService] = makeSubscriptionControllerUnit();
    seedSubscriptionUserUnit($authService);

    $payload = json_encode(['target_type' => 'agent', 'target_id' => 3]);
    $first  = $controller->unsubscribe(Request::create('/api/v1/notifications/subscriptions', 'DELETE', content: $payload));
    $second = $controller->unsubscribe(Request::create('/api/v1/notifications/subscriptions', 'DELETE', content: $payload));

    expect($first->getStatusCode())->toBe(200);
    expect($second->getStatusCode())->toBe(200);
});

test('unsubscribe() only affects the authenticated user, not other users\' subscriptions', function (): void {
    [$controller, $authService, $subscriptions] = makeSubscriptionControllerUnit();
    $userA = seedSubscriptionUserUnit($authService, 'a@example.com');
    $userB = seedSubscriptionUserUnit($authService, 'b@example.com');

    // Both users subscribe to the same target.
    $subscriptions->subscribeUserToTarget($userA, Spora\Models\NotificationSubscription::TARGET_PRINCIPAL, 4);
    $subscriptions->subscribeUserToTarget($userB, Spora\Models\NotificationSubscription::TARGET_PRINCIPAL, 4);

    // Log in as user A and unsubscribe — user B's row should remain.
    simulateLoggedInSession($userA, '1a@example.com');

    $request = Request::create(
        '/api/v1/notifications/subscriptions',
        'DELETE',
        content: json_encode(['target_type' => 'principal', 'target_id' => 4]),
    );

    $controller->unsubscribe($request);

    expect(Spora\Models\NotificationSubscription::query()->where('user_id', $userA)->where('target_id', 4)->count())->toBe(0);
    expect(Spora\Models\NotificationSubscription::query()->where('user_id', $userB)->where('target_id', 4)->count())->toBe(1);
});

test('unsubscribe() accepts the target as query-string params (DELETE-without-body interop)', function (): void {
    [$controller, $authService, $subscriptions] = makeSubscriptionControllerUnit();
    $userId = seedSubscriptionUserUnit($authService);
    $subscriptions->subscribeUserToTarget($userId, Spora\Models\NotificationSubscription::TARGET_AGENT, 11);

    $request = Request::create(
        '/api/v1/notifications/subscriptions?target_type=agent&target_id=11',
        'DELETE',
    );

    $response = $controller->unsubscribe($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Spora\Models\NotificationSubscription::query()->where('user_id', $userId)->where('target_id', 11)->count())->toBe(0);
});
