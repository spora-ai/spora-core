<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Spora\Core\RequestOrigin;

beforeEach(function (): void {
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTPS']);
});

afterEach(function (): void {
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTPS']);
});

// detect(array $config)

test('detect returns http://localhost from CLI when config is empty', function (): void {
    expect(RequestOrigin::detect())->toBe('http://localhost');
});

test('detect honors config app_url over server globals', function (): void {
    $_SERVER['HTTP_HOST'] = '127.0.0.1:8080';

    expect(RequestOrigin::detect(['app_url' => 'https://spora.fabiangrassl.de']))->toBe('https://spora.fabiangrassl.de');
});

test('detect strips trailing slash from config app_url', function (): void {
    expect(RequestOrigin::detect(['app_url' => 'https://spora.fabiangrassl.de/']))->toBe('https://spora.fabiangrassl.de');
});

test('detect does NOT read SPOra_APP_URL from $_ENV (config system owns it)', function (): void {
    // Security regression test: RequestOrigin MUST NOT bypass the framework
    // config system by reading $_ENV directly. config.php and SPOra_APP_URL
    // (via $apply()) flow through the container's `config` array, not here.
    $_ENV['SPORA_APP_URL'] = 'https://evil.example.com';
    putenv('SPORA_APP_URL=https://evil.example.com');

    expect(RequestOrigin::detect())->toBe('http://localhost');

    unset($_ENV['SPORA_APP_URL'], $_ENV['SPORA_APP_PREFIX']);
    putenv('SPORA_APP_URL');
});

// detectFrom() — HTTP_HOST path

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

// detectWithPrefix(array $config)

test('detectWithPrefix returns [baseUrl, "/spora"] by default when config is empty', function (): void {
    // The Spora admin UI ships under /spora/ (public/spora/) and plugins ship
    // under /plugins/<name>/. The default reflects that canonical URL space.
    expect(RequestOrigin::detectWithPrefix())->toBe(['http://localhost', '/spora']);
});

test('detectWithPrefix treats app_prefix="" as an explicit empty (host-root mount)', function (): void {
    expect(RequestOrigin::detectWithPrefix(['app_prefix' => '']))->toBe(['http://localhost', '']);
});

test('detectWithPrefix normalizes app_prefix=/spora', function (): void {
    [$base, $prefix] = RequestOrigin::detectWithPrefix(['app_prefix' => '/spora']);

    expect($prefix)->toBe('/spora');
    expect($base)->toBe('http://localhost');
});

test('detectWithPrefix normalizes bare "spora" to "/spora"', function (): void {
    expect(RequestOrigin::detectWithPrefix(['app_prefix' => 'spora'])[1])->toBe('/spora');
});

test('detectWithPrefix strips trailing slash from app_prefix', function (): void {
    expect(RequestOrigin::detectWithPrefix(['app_prefix' => '/spora/'])[1])->toBe('/spora');
});

test('detectWithPrefix treats app_prefix="/" as empty', function (): void {
    expect(RequestOrigin::detectWithPrefix(['app_prefix' => '/'])[1])->toBe('');
});

test('detectWithPrefix composes baseUrl + prefix from config', function (): void {
    expect(RequestOrigin::detectWithPrefix([
        'app_url'    => 'https://spora.fabiangrassl.de',
        'app_prefix' => '/spora',
    ]))->toBe(['https://spora.fabiangrassl.de', '/spora']);
});

test('detectWithPrefix does NOT read SPOra_APP_URL or SPOra_APP_PREFIX from $_ENV (config system owns them)', function (): void {
    // Security regression test: same as detect() — RequestOrigin MUST NOT
    // bypass the framework config system. config.php and SPOra_APP_URL /
    // SPOra_APP_PREFIX (via $apply()) flow through the container's `config`
    // array, not here.
    $_ENV['SPORA_APP_URL']    = 'https://evil.example.com';
    $_ENV['SPORA_APP_PREFIX'] = '/evil';
    putenv('SPORA_APP_URL=https://evil.example.com');
    putenv('SPORA_APP_PREFIX=/evil');

    expect(RequestOrigin::detectWithPrefix())->toBe(['http://localhost', '/spora']);

    unset($_ENV['SPORA_APP_URL'], $_ENV['SPORA_APP_PREFIX']);
    putenv('SPORA_APP_URL');
    putenv('SPORA_APP_PREFIX');
});

// normalizePrefix

test('normalizePrefix wraps bare names with leading slash', function (): void {
    expect(RequestOrigin::normalizePrefix('spora'))->toBe('/spora');
});

test('normalizePrefix strips trailing slash', function (): void {
    expect(RequestOrigin::normalizePrefix('/spora/'))->toBe('/spora');
});

test('normalizePrefix collapses bare "/" and empty to empty', function (): void {
    expect(RequestOrigin::normalizePrefix('/'))->toBe('');
    expect(RequestOrigin::normalizePrefix(''))->toBe('');
});
