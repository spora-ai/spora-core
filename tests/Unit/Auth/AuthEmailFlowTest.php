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
function bootEmailFlow(?string $appUrl = '', ?string $appPrefix = null): AuthEmailFlow
{
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false /* throttling off */);

    return new AuthEmailFlow($auth, $appUrl ?? '', $appPrefix ?? '/spora');
}

function capturingMailer(ArrayObject $captured): MailerInterface
{
    return new class ($captured) implements MailerInterface {
        public function __construct(private ArrayObject $captured) {}

        public function sendPasswordResetEmail(string $email, string $resetUrl): bool
        {
            $this->captured['reset'] = $resetUrl;

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
}

test('buildVerificationCallback returns null when no system mailer is wired', function (): void {
    $flow = bootEmailFlow();

    expect($flow->buildVerificationCallback('any@example.com'))->toBeNull();
});

test('buildVerificationCallback returns a callable when a system mailer is wired', function (): void {
    $captured = new ArrayObject();

    $flow = bootEmailFlow('https://spora.test', '');
    $flow->setSystemMailer(capturingMailer($captured));

    $callback = $flow->buildVerificationCallback('verify@example.com');

    expect($callback)->toBeCallable();

    $callback('selector', 'token');
    expect($captured['verify'])->toBeString();
    expect($captured['verify'])->toStartWith('https://spora.test/auth/verify/selector');
});

test('buildVerificationCallback can be invoked with a custom verify path', function (): void {
    $captured = new ArrayObject();

    $flow = bootEmailFlow('https://spora.test', '');
    $flow->setSystemMailer(capturingMailer($captured));

    $callback = $flow->buildVerificationCallback('custom@example.com', '/custom/verify/');
    $callback('abc', 'tok');

    expect($captured['verify'])->toBeString();
    expect($captured['verify'])->toStartWith('https://spora.test/custom/verify/abc');
    expect($captured['verify'])->toMatch('#\?token=tok$#');
});

test('constructor accepts appUrl and forwards it into the verification URL', function (): void {
    $captured = new ArrayObject();

    $flow = bootEmailFlow('https://forwarded.example.com', '');
    $flow->setSystemMailer(capturingMailer($captured));

    $callback = $flow->buildVerificationCallback('verify@example.com');
    $callback('selector', 'token');

    expect($captured['verify'])->toStartWith('https://forwarded.example.com/auth/verify/selector');
});

test('constructor accepts appPrefix and prepends it to the verification URL', function (): void {
    $captured = new ArrayObject();

    $flow = bootEmailFlow('https://spora.fabiangrassl.de', '/spora');
    $flow->setSystemMailer(capturingMailer($captured));

    $callback = $flow->buildVerificationCallback('verify@example.com');
    $callback('selector', 'token');

    expect($captured['verify'])->toBe('https://spora.fabiangrassl.de/spora/auth/verify/selector?token=token');
});

test('constructor normalises the appPrefix value (leading/trailing slashes, bare "/")', function (): void {
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false);

    $r = new ReflectionClass(AuthEmailFlow::class);
    $prop = $r->getProperty('appPrefix');
    $prop->setAccessible(true);

    $flow = new AuthEmailFlow($auth, '', 'spora');
    expect($prop->getValue($flow))->toBe('/spora');

    $flow = new AuthEmailFlow($auth, '', '/trailing/');
    expect($prop->getValue($flow))->toBe('/trailing');

    $flow = new AuthEmailFlow($auth, '', '/');
    expect($prop->getValue($flow))->toBe('');
});

test('constructor defaults to /spora when no prefix is given', function (): void {
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false);

    $r = new ReflectionClass(AuthEmailFlow::class);
    $prop = $r->getProperty('appPrefix');

    $flow = new AuthEmailFlow($auth);
    expect($prop->getValue($flow))->toBe('/spora');
});

test('prefix is empty when explicitly opted out and no http://localhost:port sneaks in', function (): void {
    $captured = new ArrayObject();

    $flow = bootEmailFlow('', '');
    $flow->setSystemMailer(capturingMailer($captured));

    $callback = $flow->buildVerificationCallback('verify@example.com');
    $callback('selector', 'token');

    // Empty prefix → URL has no /spora segment. Base falls back to http://localhost.
    expect($captured['verify'])->toBe('http://localhost/auth/verify/selector?token=token');
});

test('default /spora prefix lands the verification URL on the SPA route', function (): void {
    $captured = new ArrayObject();

    $flow = bootEmailFlow('https://spora.fabiangrassl.de');
    $flow->setSystemMailer(capturingMailer($captured));

    $callback = $flow->buildVerificationCallback('verify@example.com');
    $callback('selector', 'token');

    expect($captured['verify'])->toBe('https://spora.fabiangrassl.de/spora/auth/verify/selector?token=token');
});
