<?php

declare(strict_types=1);

use Spora\Security\CsrfTokenService;

beforeEach(function (): void {
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    unset($_SESSION['csrf_token']);
});

test('generate() returns a 64-character hex token', function (): void {
    $svc = new CsrfTokenService();
    $token = $svc->generate();

    expect(strlen($token))->toBe(64);
    expect($token)->toMatch('/^[a-f0-9]{64}$/');
});

test('generate() stores token in session and overwrites any existing value', function (): void {
    $svc = new CsrfTokenService();
    $svc->generate();
    $first = $svc->getToken();
    $svc->generate();
    $second = $svc->getToken();

    expect($first)->not->toBe($second);
});

test('getToken() returns null when no token is present', function (): void {
    $svc = new CsrfTokenService();
    expect($svc->getToken())->toBeNull();
});

test('getOrCreateToken() generates token when missing', function (): void {
    $svc = new CsrfTokenService();
    $token = $svc->getOrCreateToken();

    expect($token)->toBeString();
    expect(strlen($token))->toBe(64);
    expect($svc->getToken())->toBe($token);
});

test('getOrCreateToken() returns existing token when present', function (): void {
    $svc = new CsrfTokenService();
    $first = $svc->getOrCreateToken();
    $second = $svc->getOrCreateToken();

    expect($first)->toBe($second);
});

test('validate() returns true for matching token (constant-time)', function (): void {
    $svc = new CsrfTokenService();
    $token = $svc->generate();

    expect($svc->validate($token))->toBeTrue();
});

test('validate() returns false for mismatched token', function (): void {
    $svc = new CsrfTokenService();
    $svc->generate();

    expect($svc->validate('not-the-token'))->toBeFalse();
});

test('validate() returns false when no token is in session', function (): void {
    $svc = new CsrfTokenService();

    expect($svc->validate('any-value'))->toBeFalse();
});

test('regenerate() invalidates the previous token and returns a new one', function (): void {
    $svc = new CsrfTokenService();
    $old = $svc->generate();

    $new = $svc->regenerate();

    expect($new)->not->toBe($old);
    expect($svc->validate($old))->toBeFalse();
    expect($svc->validate($new))->toBeTrue();
});

test('invalidate() removes the token from the session', function (): void {
    $svc = new CsrfTokenService();
    $svc->generate();

    $svc->invalidate();

    expect($svc->getToken())->toBeNull();
});
