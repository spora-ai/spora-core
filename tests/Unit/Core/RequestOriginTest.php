<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Spora\Core\RequestOrigin;

function detectWithServer(array $server): string
{
    return RequestOrigin::detectFrom($server);
}

afterEach(function (): void {
    // No global state to clean — detectFrom() takes the server array as a parameter.
});

test('detects plain HTTP host on default port', function (): void {
    expect(detectWithServer(['HTTP_HOST' => 'spora.local', 'REQUEST_SCHEME' => 'http', 'SERVER_PORT' => 80]))
        ->toBe('http://spora.local');
});

test('detects HTTPS host on default port', function (): void {
    expect(detectWithServer(['HTTP_HOST' => 'spora.local', 'REQUEST_SCHEME' => 'https', 'SERVER_PORT' => 443]))
        ->toBe('https://spora.local');
});

test('appends non-default port when host does not contain one', function (): void {
    expect(detectWithServer(['HTTP_HOST' => 'spora.local', 'REQUEST_SCHEME' => 'http', 'SERVER_PORT' => 8080]))
        ->toBe('http://spora.local:8080');
});

test('does not duplicate port when host already contains one', function (): void {
    // This is the regression that produced http://localhost:8080:8080.
    expect(detectWithServer(['HTTP_HOST' => 'localhost:8080', 'REQUEST_SCHEME' => 'http', 'SERVER_PORT' => 8080]))
        ->toBe('http://localhost:8080');
});

test('prefers X-Forwarded-Host when behind a reverse proxy', function (): void {
    expect(detectWithServer([
        'HTTP_HOST'              => '127.0.0.1:8080',
        'HTTP_X_FORWARDED_HOST'  => 'spora.fabiangrassl.de',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'SERVER_PORT'            => 8080,
    ]))->toBe('https://spora.fabiangrassl.de');
});

test('prefers X-Forwarded-Proto over REQUEST_SCHEME', function (): void {
    expect(detectWithServer([
        'HTTP_HOST'              => 'spora.local',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'REQUEST_SCHEME'         => 'http',
        'SERVER_PORT'            => 443,
    ]))->toBe('https://spora.local');
});

test('takes the first value of comma-separated X-Forwarded headers', function (): void {
    expect(detectWithServer([
        'HTTP_HOST'              => '127.0.0.1',
        'HTTP_X_FORWARDED_HOST'  => 'spora.fabiangrassl.de, internal.upstream',
        'HTTP_X_FORWARDED_PROTO' => 'https, http',
        'HTTP_X_FORWARDED_PORT'  => '443, 8080',
        'SERVER_PORT'            => 80,
    ]))->toBe('https://spora.fabiangrassl.de');
});

test('returns localhost when no HTTP_HOST is present', function (): void {
    expect(detectWithServer([]))->toBe('http://localhost');
});
