<?php

declare(strict_types=1);

use Spora\Auth\AuthEmailFlow;
use Spora\Services\MailerInterface;

/**
 * Focused unit tests for {@see AuthEmailFlow}. The full happy path
 * (changeEmail, forgotPassword, resendVerificationEmail, confirmEmail) is
 * exercised through the {@see Spora\Auth\AuthService} facade in
 * AuthServiceTest; this file pins the pure helpers that the facade relies on.
 */
function bootEmailFlow(): AuthEmailFlow
{
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false /* throttling off */);

    return new AuthEmailFlow($auth);
}

test('buildVerificationCallback returns null when no system mailer is wired', function (): void {
    $flow = bootEmailFlow();

    expect($flow->buildVerificationCallback('any@example.com'))->toBeNull();
});

test('buildVerificationCallback returns a callable when a system mailer is wired', function (): void {
    $captured = new ArrayObject();
    $mailer = new class ($captured) implements MailerInterface {
        public function __construct(private ArrayObject $captured) {}

        public function sendPasswordResetEmail(string $email, string $resetUrl): bool
        {
            return true;
        }

        public function sendVerificationEmail(string $email, string $verificationUrl): bool
        {
            $this->captured['verify'] = $verificationUrl;

            return true;
        }

        public function sendWelcomeEmail(int $userId, string $email): bool
        {
            return true;
        }
    };

    $flow = bootEmailFlow();
    $flow->setSystemMailer($mailer);
    $flow->setAppUrl('https://spora.test');

    $callback = $flow->buildVerificationCallback('verify@example.com');

    expect($callback)->toBeCallable();

    $callback('selector', 'token');
    expect($captured['verify'])->toBeString();
    expect($captured['verify'])->toStartWith('https://spora.test/auth/verify/selector');
});

test('buildVerificationCallback can be invoked with a custom verify path', function (): void {
    $captured = new ArrayObject();

    $mailer = new class ($captured) implements MailerInterface {
        public function __construct(private ArrayObject $captured) {}

        public function sendPasswordResetEmail(string $email, string $resetUrl): bool
        {
            return true;
        }

        public function sendVerificationEmail(string $email, string $verificationUrl): bool
        {
            $this->captured['verify'] = $verificationUrl;

            return true;
        }

        public function sendWelcomeEmail(int $userId, string $email): bool
        {
            return true;
        }
    };

    $flow = bootEmailFlow();
    $flow->setSystemMailer($mailer);
    $flow->setAppUrl('https://spora.test');

    $callback = $flow->buildVerificationCallback('custom@example.com', '/custom/verify/');
    $callback('abc', 'tok');

    expect($captured['verify'])->toBeString();
    expect($captured['verify'])->toStartWith('https://spora.test/custom/verify/abc');
    expect($captured['verify'])->toMatch('#\?token=tok$#');
});

test('setAppUrl is accepted on the flow (used by the facade to forward configuration)', function (): void {
    $flow = bootEmailFlow();

    $flow->setAppUrl('https://forwarded.example.com');

    expect($flow)->toBeInstanceOf(AuthEmailFlow::class);
});
