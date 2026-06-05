<?php

declare(strict_types=1);

use Spora\Services\RateLimiter;

afterEach(fn() => RateLimiter::resetAll());

test('attempt() returns false for the first maxAttempts calls', function (): void {
    $id = 'client_1';
    $max = 3;
    $window = 60;

    expect(RateLimiter::attempt($id, $max, $window))->toBeFalse();
    expect(RateLimiter::attempt($id, $max, $window))->toBeFalse();
    expect(RateLimiter::attempt($id, $max, $window))->toBeFalse();
});

test('attempt() returns true once threshold is reached', function (): void {
    $id = 'client_2';
    $max = 2;
    $window = 60;

    RateLimiter::attempt($id, $max, $window);
    RateLimiter::attempt($id, $max, $window);

    expect(RateLimiter::attempt($id, $max, $window))->toBeTrue();
});

test('remaining() returns maxAttempts for an unknown identifier', function (): void {
    expect(RateLimiter::remaining('unknown', 5, 60))->toBe(5);
});

test('remaining() returns decreasing count as attempts are made', function (): void {
    $id = 'client_3';
    $max = 5;
    $window = 60;

    RateLimiter::attempt($id, $max, $window);
    expect(RateLimiter::remaining($id, $max, $window))->toBe(4);

    RateLimiter::attempt($id, $max, $window);
    RateLimiter::attempt($id, $max, $window);
    expect(RateLimiter::remaining($id, $max, $window))->toBe(2);
});

test('remaining() returns 0 when threshold is hit', function (): void {
    $id = 'client_4';
    $max = 2;
    $window = 60;

    RateLimiter::attempt($id, $max, $window);
    RateLimiter::attempt($id, $max, $window);

    expect(RateLimiter::remaining($id, $max, $window))->toBe(0);
});

test('retryAfter() returns 0 for an unknown identifier', function (): void {
    expect(RateLimiter::retryAfter('unknown', 60))->toBe(0);
});

test('retryAfter() returns a positive value within the window', function (): void {
    $id = 'client_5';

    RateLimiter::attempt($id, 5, 60);
    $remaining = RateLimiter::retryAfter($id, 60);

    expect($remaining)->toBeGreaterThan(0);
    expect($remaining)->toBeLessThanOrEqual(60);
});

test('clear() removes the bucket for a single identifier', function (): void {
    $id = 'client_6';

    RateLimiter::attempt($id, 5, 60);
    expect(RateLimiter::remaining($id, 5, 60))->toBe(4);

    RateLimiter::clear($id);
    expect(RateLimiter::remaining($id, 5, 60))->toBe(5);
});

test('resetAll() clears every bucket', function (): void {
    RateLimiter::attempt('a', 5, 60);
    RateLimiter::attempt('b', 5, 60);

    RateLimiter::resetAll();

    expect(RateLimiter::remaining('a', 5, 60))->toBe(5);
    expect(RateLimiter::remaining('b', 5, 60))->toBe(5);
});
