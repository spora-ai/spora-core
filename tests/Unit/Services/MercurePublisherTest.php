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

test('publishForPrincipal and publishToUser do not throw when no logger is provided', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $client->shouldReceive('request')->andReturn($response);

    $publisher = new MercurePublisher(
        $client,
        'http://mercure/.well-known/mercure',
        'secret1234secret1234secret1234secret1234',
    );

    expect($publisher->publishForPrincipal(1, 2, ['x' => 1]))->toBeTrue();
    expect($publisher->publishToUser(2, ['x' => 1]))->toBeTrue();
});

test('publishForPrincipal and publishToUser do not throw when logger is provided and no error occurs', function (): void {
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

    expect($publisher->publishForPrincipal(1, 2, ['x' => 1]))->toBeTrue();
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

/**
 * The wire shape, which the tests above do not reach: they assert return values
 * and log records, and both are satisfied whatever `doPublish()` puts on the
 * body. A hub receiving `{topic, data}` with `data` as a JSON *string* would
 * silently deliver nothing to every subscriber.
 */
test('sends topic and payload nested inside the request body', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $hubUrl = 'http://mercure/.well-known/mercure';

    $client->shouldReceive('request')
        ->once()
        ->with('POST', $hubUrl, Mockery::on(function (array $options) {
            expect($options['body']['topic'])->toBe('user/123/notifications');

            $parsed = json_decode($options['body']['data'], true);
            expect($parsed['topic'])->toBe('user/123/notifications')
                ->and($parsed['data']['event'])->toBe('notification')
                ->and($parsed['data']['notification'])->toBe('test_value');

            return true;
        }))
        ->andReturn(Mockery::mock(ResponseInterface::class));

    $publisher = new MercurePublisher($client, $hubUrl, 'secret1234secret1234secret1234secret1234');

    expect($publisher->publishToUser(123, ['event' => 'notification', 'notification' => 'test_value']))->toBeTrue();
});

/**
 * The publisher token is the only thing standing between a topic and anyone
 * with the hub URL, so it grants exactly the topic being published and nothing
 * else — a wildcard or a second topic would let a compromised task payload
 * reach unrelated subscribers.
 */
test('the publisher JWT grants only the topic being published', function (): void {
    $client = Mockery::mock(HttpClientInterface::class);
    $hubUrl = 'http://mercure/.well-known/mercure';

    $client->shouldReceive('request')
        ->once()
        ->with('POST', $hubUrl, Mockery::on(function (array $options) {
            $parts = explode('.', $options['auth_bearer']);
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            expect($payload['mercure']['publish'])->toBe(['principal/42/tasks']);

            return true;
        }))
        ->andReturn(Mockery::mock(ResponseInterface::class));

    $publisher = new MercurePublisher($client, $hubUrl, 'secret1234secret1234secret1234secret1234');
    $publisher->publishForPrincipal(99, 42, ['status' => 'RUNNING']);
});
