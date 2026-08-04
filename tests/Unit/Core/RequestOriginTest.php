<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Spora\Core\RequestOrigin;

beforeEach(function (): void {
    unset($_ENV['SPORA_APP_URL'], $_ENV['SPORA_APP_PREFIX']);
    putenv('SPORA_APP_URL');
    putenv('SPORA_APP_PREFIX');
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTPS']);
});

afterEach(function (): void {
    unset($_ENV['SPORA_APP_URL'], $_ENV['SPORA_APP_PREFIX']);
    putenv('SPORA_APP_URL');
    putenv('SPORA_APP_PREFIX');
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTPS']);
});

// ---------------------------------------------------------------------------
// detect()
// ---------------------------------------------------------------------------

test('detect returns http://localhost from CLI with no env', function (): void {
    expect(RequestOrigin::detect())->toBe('http://localhost');
});

test('detect honors SPOra_APP_URL env even from CLI', function (): void {
    $_ENV['SPORA_APP_URL'] = 'https://spora.fabiangrassl.de';
    putenv('SPORA_APP_URL=https://spora.fabiangrassl.de');

    expect(RequestOrigin::detect())->toBe('https://spora.fabiangrassl.de');
});

test('detect strips trailing slash from SPOra_APP_URL', function (): void {
    $_ENV['SPORA_APP_URL'] = 'https://spora.fabiangrassl.de/';
    putenv('SPORA_APP_URL=https://spora.fabiangrassl.de/');

    expect(RequestOrigin::detect())->toBe('https://spora.fabiangrassl.de');
});

// ---------------------------------------------------------------------------
// detectFrom() — HTTP_HOST path
// ---------------------------------------------------------------------------

test('detectFrom returns http://localhost when host is empty', function (): void {
    expect(RequestOrigin::detectFrom([]))->toBe('http://localhost');
});

test('detectFrom falls back to SERVER_NAME when HTTP_HOST is missing', function (): void {
    expect(RequestOrigin::detectFrom([
        'SERVER_NAME' => 'spora.fabiangrassl.de',
        'SERVER_PORT' => 443,
        'HTTPS'       => 'on',
    ]))->toBe('https://spora.fabiangrassl.de');
});

test('detectFrom prefers HTTP_HOST over SERVER_NAME when both are present', function (): void {
    // HTTP_HOST carries a forwarded Host header (per-request, possibly client-influenced),
    // SERVER_NAME is the canonical server-level config. We prefer HTTP_HOST because it
    // reflects the public origin the operator chose to expose for this request.
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST'   => 'public.example.com',
        'SERVER_NAME' => 'internal.example.com',
    ]))->toBe('http://public.example.com');
});

test('detectFrom builds the URL from HTTP_HOST + default http port 80', function (): void {
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST' => 'spora.example.com',
    ]))->toBe('http://spora.example.com');
});

test('detectFrom appends a non-default port from SERVER_PORT', function (): void {
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST'   => 'spora.example.com',
        'SERVER_PORT' => 8080,
    ]))->toBe('http://spora.example.com:8080');
});

test('detectFrom uses https scheme when REQUEST_SCHEME is https', function (): void {
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST'      => 'spora.example.com',
        'REQUEST_SCHEME' => 'https',
    ]))->toBe('https://spora.example.com');
});

test('detectFrom falls back to HTTPS=on when REQUEST_SCHEME is absent', function (): void {
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST' => 'spora.example.com',
        'HTTPS'     => 'on',
    ]))->toBe('https://spora.example.com');
});

test('detectFrom strips default https port 443', function (): void {
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST'      => 'spora.example.com',
        'REQUEST_SCHEME' => 'https',
        'SERVER_PORT'    => 443,
    ]))->toBe('https://spora.example.com');
});

test('detectFrom strips port from HTTP_HOST to avoid the :8080:8080 regression', function (): void {
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST'   => 'spora.example.com:8080',
        'SERVER_PORT' => 8080,
    ]))->toBe('http://spora.example.com:8080');
});

test('detectFrom preserves IPv6 brackets in HTTP_HOST and strips the port', function (): void {
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST'   => '[::1]:8080',
        'SERVER_PORT' => 8080,
    ]))->toBe('http://[::1]:8080');
});

test('detectFrom does NOT read X-Forwarded-* (security: spoofable)', function (): void {
    // The whole point of removing X-Forwarded-* detection: a client can
    // submit these headers and contaminate the public URL used in
    // email-verification links. RequestOrigin MUST ignore them.
    expect(RequestOrigin::detectFrom([
        'HTTP_HOST'              => 'spora.example.com',
        'HTTP_X_FORWARDED_HOST'  => 'evil.example.com',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT'  => '443',
    ]))->toBe('http://spora.example.com');
});

// ---------------------------------------------------------------------------
// detectWithPrefix()
// ---------------------------------------------------------------------------

test('detectWithPrefix returns [baseUrl, ""] when no prefix is set', function (): void {
    expect(RequestOrigin::detectWithPrefix())->toBe(['http://localhost', '']);
});

test('detectWithPrefix normalizes SPOra_APP_PREFIX=/spora', function (): void {
    $_ENV['SPORA_APP_PREFIX'] = '/spora';
    putenv('SPORA_APP_PREFIX=/spora');

    [$base, $prefix] = RequestOrigin::detectWithPrefix();

    expect($prefix)->toBe('/spora');
    expect($base)->toBe('http://localhost');
});

test('detectWithPrefix normalizes bare "spora" to "/spora"', function (): void {
    $_ENV['SPORA_APP_PREFIX'] = 'spora';
    putenv('SPORA_APP_PREFIX=spora');

    expect(RequestOrigin::detectWithPrefix()[1])->toBe('/spora');
});

test('detectWithPrefix strips trailing slash from SPOra_APP_PREFIX', function (): void {
    $_ENV['SPORA_APP_PREFIX'] = '/spora/';
    putenv('SPORA_APP_PREFIX=/spora/');

    expect(RequestOrigin::detectWithPrefix()[1])->toBe('/spora');
});

test('detectWithPrefix treats SPOra_APP_PREFIX="/" as empty', function (): void {
    $_ENV['SPORA_APP_PREFIX'] = '/';
    putenv('SPORA_APP_PREFIX=/');

    expect(RequestOrigin::detectWithPrefix()[1])->toBe('');
});

test('detectWithPrefix composes baseUrl + prefix', function (): void {
    $_ENV['SPORA_APP_URL'] = 'https://spora.fabiangrassl.de';
    $_ENV['SPORA_APP_PREFIX'] = '/spora';
    putenv('SPORA_APP_URL=https://spora.fabiangrassl.de');
    putenv('SPORA_APP_PREFIX=/spora');

    expect(RequestOrigin::detectWithPrefix())->toBe(['https://spora.fabiangrassl.de', '/spora']);
});
