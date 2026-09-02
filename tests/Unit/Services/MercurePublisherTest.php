<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Services\MercurePublisher;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function mercureCapturingLogger(): MercurePublisherCapturingLogger
{
    return new MercurePublisherCapturingLogger();
}

/**
 * Seed a group principal with N user members. Returns the principal id.
 * Used by tests that need PrincipalResolver::visibleUserIds to return
 * a known set of user ids (group fan-out assertions).
 */
function seedGroupPrincipalWithMembers(int $memberCount): int
{
    $pdo = \Illuminate\Database\Capsule\Manager::connection();
    $now = gmdate('Y-m-d H:i:s');

    $ownerId = bootAuthLayer()->register('group-owner@example.test', 'Password1!', 'Owner');

    $groupId = (int) $pdo->table('groups')->insertGetId([
        'name'              => 'Test Group',
        'created_by_user_id' => $ownerId,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $principalId = (int) $pdo->table('principals')->insertGetId([
        'type'       => 'group',
        'group_id'   => $groupId,
        'user_id'    => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    for ($i = 0; $i < $memberCount; $i++) {
        $userId = (int) $pdo->table('users')->insertGetId([
            'email'      => "member{$i}@example.test",
            'password'   => password_hash('Password1!', PASSWORD_BCRYPT),
            'username'   => "member{$i}",
            'registered' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pdo->table('group_memberships')->insert([
            'group_id'   => $groupId,
            'user_id'    => $userId,
            'role'       => 'member',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $principalId;
}

test('publish returns false and does not call HTTP client when hubUrl is null', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, null, 'jwt-key', $logger);

    $result = $publisher->publish(99, 42, ['status' => 'RUNNING']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('publish skipped');
});

test('publish returns false and does not call HTTP client when jwtKey is null', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, 'http://mercure/.well-known/mercure', null, $logger);

    $result = $publisher->publish(99, 42, ['status' => 'RUNNING']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['message'])->toContain('publish skipped');
});

test('publish returns false and logs error when HTTP client throws', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new RuntimeException('Connection refused'));

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publish(99, 42, ['status' => 'RUNNING']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(2);
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('publish called');
    expect($logger->records[1]['level'])->toBe('error');
    expect($logger->records[1]['message'])->toContain('publish failed');
    expect($logger->records[1]['context']['error'])->toBe('Connection refused');
    expect($logger->records[1]['context']['task_id'])->toBe(99);
    expect($logger->records[1]['context']['subject_id'])->toBe(42);
});

test('publish returns true on success and logs info with HTTP status', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);

    $client->shouldReceive('request')->once()->andReturn($response);

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publish(99, 42, ['status' => 'RUNNING']);

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(2);
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('publish called');
    expect($logger->records[1]['level'])->toBe('info');
    expect($logger->records[1]['message'])->toContain('published task event');
    expect($logger->records[1]['context']['http_status'])->toBe(200);
    expect($logger->records[1]['context']['task_id'])->toBe(99);
    expect($logger->records[1]['context']['subject_id'])->toBe(42);
});

test('publish logs error when HTTP response is 4xx', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(401);

    $client->shouldReceive('request')->once()->andReturn($response);

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publish(99, 42, ['status' => 'RUNNING']);

    // 4xx is still treated as success because no exception was thrown
    expect($result)->toBeTrue();
    expect($logger->records[1]['context']['http_status'])->toBe(401);
});

test('publishToUser returns false and does not call HTTP client when hubUrl is null', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, null, 'jwt-key', $logger);

    $result = $publisher->publishToUser(42, ['event' => 'notification']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('publishToUser skipped');
});

test('publishToUser returns false and does not call HTTP client when jwtKey is null', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, 'http://mercure/.well-known/mercure', null, $logger);

    $result = $publisher->publishToUser(42, ['event' => 'notification']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['message'])->toContain('publishToUser skipped');
});

test('publishToUser returns false and logs error when HTTP client throws', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new RuntimeException('Network unreachable'));

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publishToUser(42, ['event' => 'notification']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(2);
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('publish called');
    expect($logger->records[1]['level'])->toBe('error');
    expect($logger->records[1]['message'])->toContain('publish failed');
    expect($logger->records[1]['context']['error'])->toBe('Network unreachable');
    expect($logger->records[1]['context']['subject_id'])->toBe(42);
});

test('publishToUser returns true on success and logs info with HTTP status', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(204);

    $client->shouldReceive('request')->once()->andReturn($response);

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publishToUser(42, ['event' => 'notification']);

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(2);
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('publish called');
    expect($logger->records[1]['level'])->toBe('info');
    expect($logger->records[1]['message'])->toContain('published user notification');
    expect($logger->records[1]['context']['http_status'])->toBe(204);
    expect($logger->records[1]['context']['subject_id'])->toBe(42);
});

test('publishToUser logs error when HTTP response is 5xx', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(500);

    $client->shouldReceive('request')->once()->andReturn($response);

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publishToUser(42, ['event' => 'notification']);

    // 5xx is still treated as success because no exception was thrown
    expect($result)->toBeTrue();
    expect($logger->records[1]['context']['http_status'])->toBe(500);
});

test('publish and publishToUser do not throw when no logger is provided', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $client->shouldReceive('request')->andReturn($response);

    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
    );

    expect($publisher->publish(1, 2, ['x' => 1]))->toBeTrue();
    expect($publisher->publishToUser(2, ['x' => 1]))->toBeTrue();
});

test('publish and publishToUser do not throw when logger is provided and no error occurs', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $client->shouldReceive('request')->andReturn($response);

    // Pass a mock logger that allows any calls
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('debug')->zeroOrMoreTimes();
    $logger->shouldReceive('info')->zeroOrMoreTimes();
    $logger->shouldReceive('error')->zeroOrMoreTimes();

    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    expect($publisher->publish(1, 2, ['x' => 1]))->toBeTrue();
    expect($publisher->publishToUser(2, ['x' => 1]))->toBeTrue();
});

test('publishForPrincipal returns false and does not call HTTP client when hubUrl is null', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, null, 'jwt-key', $logger);

    $result = $publisher->publishForPrincipal(7, 13, ['status' => 'PENDING_APPROVAL']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['level'])->toBe('debug');
    expect($logger->records[0]['message'])->toContain('publishForPrincipal skipped');
});

test('publishForPrincipal returns false when jwtKey is null', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, 'http://mercure/.well-known/mercure', null, $logger);

    expect($publisher->publishForPrincipal(7, 13, ['status' => 'PENDING_APPROVAL']))->toBeFalse();
});

test('publishForPrincipal publishes to principal-keyed topic on success', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $client->shouldReceive('request')->once()->andReturn($response);

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publishForPrincipal(7, 13, ['status' => 'PENDING_APPROVAL']);

    expect($result)->toBeTrue();
    expect($logger->records[1]['level'])->toBe('info');
    expect($logger->records[1]['message'])->toContain('published task event');
    expect($logger->records[1]['context']['topic'])->toBe('principal/13/tasks');
    expect($logger->records[1]['context']['subject_id'])->toBe(13);
    expect($logger->records[1]['context']['task_id'])->toBe(7);
});

test('publishForPrincipal falls back to user-keyed topics when legacy rollout is enabled', function (): void {
    // Real PrincipalResolver against the in-memory test DB. Seed a group
    // principal with 3 members so visibleUserIds returns them.
    $resolver = new \Spora\Services\PrincipalResolver();
    $principalId = seedGroupPrincipalWithMembers(3);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);

    // 1 principal publish + 3 user-keyed publishes (group with 3 members).
    $client->shouldReceive('request')->times(4)->andReturn($response);

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
        true,
        $resolver,
    );

    $result = $publisher->publishForPrincipal(7, $principalId, ['status' => 'PENDING_APPROVAL']);

    expect($result)->toBeTrue();
    // Each publish generates a debug + info log record; dedupe by topic.
    $topics = array_unique(array_map(
        static fn(array $r): string => $r['context']['topic'] ?? '',
        $logger->records,
    ));
    expect($topics)->toContain("principal/{$principalId}/tasks");
    expect(array_filter($topics, static fn(string $t): bool => str_starts_with($t, 'user/') && str_ends_with($t, '/tasks')))
        ->toHaveCount(3);
});

test('publishForPrincipal does not fall back when legacy rollout is disabled', function (): void {
    $resolver = new \Spora\Services\PrincipalResolver();
    $principalId = seedGroupPrincipalWithMembers(3);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $client->shouldReceive('request')->once()->andReturn($response);

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
        false,
        $resolver,
    );

    expect($publisher->publishForPrincipal(7, $principalId, ['status' => 'PENDING_APPROVAL']))->toBeTrue();
});

test('publishForPrincipal returns false and logs error when HTTP client throws', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldReceive('request')
        ->once()
        ->andThrow(new RuntimeException('Hub down'));

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
        $logger,
    );

    $result = $publisher->publishForPrincipal(7, 13, ['status' => 'PENDING_APPROVAL']);

    expect($result)->toBeFalse();
    expect($logger->records[1]['level'])->toBe('error');
    expect($logger->records[1]['message'])->toContain('publish failed');
    expect($logger->records[1]['context']['error'])->toBe('Hub down');
    expect($logger->records[1]['context']['task_id'])->toBe(7);
    expect($logger->records[1]['context']['subject_id'])->toBe(13);
});
