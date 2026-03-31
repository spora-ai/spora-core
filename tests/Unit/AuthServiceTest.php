<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\Exceptions\AccountUnverifiedException;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Mark a registered user's account as unverified in the database
 * so that login() will throw EmailNotVerifiedException from delight-im.
 */
function markAccountUnverified(string $email): void
{
    Capsule::table('users')->where('email', $email)->update(['verified' => 0]);
}

// ---------------------------------------------------------------------------
// register() — invalid input branches
// ---------------------------------------------------------------------------

test('register() with an invalid email format throws InvalidArgumentException', function (): void {
    $service = bootAuthLayer();

    expect(fn() => $service->register('not-an-email', 'ValidPass1!'))->toThrow(InvalidArgumentException::class);
});

test('register() with a blank password throws InvalidArgumentException', function (): void {
    $service = bootAuthLayer();

    expect(fn() => $service->register('user@example.com', ''))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// login() — unverified account branch
// ---------------------------------------------------------------------------

test('login() throws AccountUnverifiedException when account is not verified', function (): void {
    $service = bootAuthLayer();
    $email   = 'unverified@example.com';
    $service->register($email, 'ValidPass1!');

    markAccountUnverified($email);

    expect(fn() => $service->login($email, 'ValidPass1!'))->toThrow(AccountUnverifiedException::class);
});

// ---------------------------------------------------------------------------
// currentUserEmail()
// ---------------------------------------------------------------------------

test('currentUserEmail() returns null when not logged in', function (): void {
    clearSession();
    $service = bootAuthLayer();

    expect($service->currentUserEmail())->toBeNull();
});

test('currentUserEmail() returns the email of the logged-in user', function (): void {
    $service = bootAuthLayer();
    $email   = 'logged-in@example.com';
    $service->register($email, 'ValidPass1!');
    $service->login($email, 'ValidPass1!');

    expect($service->currentUserEmail())->toBe($email);
});
