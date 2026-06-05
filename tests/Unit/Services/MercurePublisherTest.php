<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Spora\Services\MercurePublisher;

/**
 * Test logger that records every log call for later assertions.
 */
final class MercurePublisherCapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

function mercureCapturingLogger(): MercurePublisherCapturingLogger
{
    return new MercurePublisherCapturingLogger();
}

test('publish returns false and does not call HTTP client when hubUrl is null', function (): void {
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
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
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, 'http://mercure/.well-known/mercure', null, $logger);

    $result = $publisher->publish(99, 42, ['status' => 'RUNNING']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['message'])->toContain('publish skipped');
});

test('publish returns false and logs error when HTTP client throws', function (): void {
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
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
    expect($logger->records[1]['context']['user_id'])->toBe(42);
});

test('publish returns true on success and logs info with HTTP status', function (): void {
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
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
    expect($logger->records[1]['context']['user_id'])->toBe(42);
});

test('publish logs error when HTTP response is 4xx', function (): void {
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
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
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
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
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $logger = mercureCapturingLogger();
    $publisher = new MercurePublisher($client, 'http://mercure/.well-known/mercure', null, $logger);

    $result = $publisher->publishToUser(42, ['event' => 'notification']);

    expect($result)->toBeFalse();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['message'])->toContain('publishToUser skipped');
});

test('publishToUser returns false and logs error when HTTP client throws', function (): void {
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
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
    expect($logger->records[0]['message'])->toContain('publishToUser called');
    expect($logger->records[1]['level'])->toBe('error');
    expect($logger->records[1]['message'])->toContain('publishToUser failed');
    expect($logger->records[1]['context']['error'])->toBe('Network unreachable');
    expect($logger->records[1]['context']['user_id'])->toBe(42);
});

test('publishToUser returns true on success and logs info with HTTP status', function (): void {
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
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
    expect($logger->records[0]['message'])->toContain('publishToUser called');
    expect($logger->records[1]['level'])->toBe('info');
    expect($logger->records[1]['message'])->toContain('published user notification');
    expect($logger->records[1]['context']['http_status'])->toBe(204);
    expect($logger->records[1]['context']['user_id'])->toBe(42);
});

test('publishToUser logs error when HTTP response is 5xx', function (): void {
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
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
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
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
    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $client->shouldReceive('request')->andReturn($response);

    // Pass a mock logger that allows any calls
    $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
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
