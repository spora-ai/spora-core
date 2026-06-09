<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\Exceptions\AccountUnverifiedException;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Auth\Exceptions\InvalidCredentialsException;
use Spora\Models\User;
use Spora\Services\MailerInterface;
use Spora\Services\SystemMailer;

/**
 * Mark a registered user's account as unverified in the database
 * so that login() will throw EmailNotVerifiedException from delight-im.
 */
function markAccountUnverified(string $email): void
{
    Capsule::table('users')->where('email', $email)->update(['verified' => 0]);
}

/**
 * Build a MailerInterface test double that records the most recent
 * verification / password-reset URL passed to it. Returned as a tuple
 * of (mailer, captured) where `$captured` is an ArrayObject so the
 * proxy and the assertion side share the same reference.
 *
 * @return array{0: MailerInterface, 1: ArrayObject}
 */
function makeCapturingMailer(): array
{
    $captured = new ArrayObject(['verify' => null, 'reset' => null]);

    $mailer = new class ($captured) implements MailerInterface {
        private SystemMailer $inner;
        /** @var ArrayObject */
        private ArrayObject $captured;

        public function __construct(ArrayObject $captured)
        {
            $this->inner = new SystemMailer(['mail_driver' => 'log']);
            $this->captured = $captured;
        }

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
            return $this->inner->sendWelcomeEmail($userId, $email);
        }
    };

    return [$mailer, $captured];
}

test('register() with an invalid email format throws InvalidArgumentException', function (): void {
    $service = bootAuthLayer();

    expect(fn() => $service->register('not-an-email', 'ValidPass1!', 'Not An Email'))->toThrow(InvalidArgumentException::class);
});

test('register() with a blank password throws InvalidArgumentException', function (): void {
    $service = bootAuthLayer();

    expect(fn() => $service->register('user@example.com', '', 'User'))->toThrow(InvalidArgumentException::class);
});

test('login() throws AccountUnverifiedException when account is not verified', function (): void {
    $service = bootAuthLayer();
    $email   = 'unverified@example.com';
    $service->register($email, 'ValidPass1!', 'Unverified');

    markAccountUnverified($email);

    expect(fn() => $service->login($email, 'ValidPass1!'))->toThrow(AccountUnverifiedException::class);
});

test('currentUserEmail() returns null when not logged in', function (): void {
    clearSession();
    $service = bootAuthLayer();

    expect($service->currentUserEmail())->toBeNull();
});

test('currentUserEmail() returns the email of the logged-in user', function (): void {
    $service = bootAuthLayer();
    $email   = 'logged-in@example.com';
    $service->register($email, 'ValidPass1!', 'Logged In');
    $service->login($email, 'ValidPass1!');

    expect($service->currentUserEmail())->toBe($email);
});

test('confirmEmail() throws InvalidSelectorTokenPairException for unknown selector', function (): void {
    $service = bootAuthLayer();

    expect(fn() => $service->confirmEmail('invalid-selector', 'invalid-token'))
        ->toThrow(Delight\Auth\InvalidSelectorTokenPairException::class);
});

test('resendVerificationEmail() without system mailer does not throw', function (): void {
    $service = bootAuthLayer();

    // Without a system mailer, the method is a no-op and returns without throwing.
    // Exceptions from delight-im are caught internally.
    $threw = false;
    try {
        $service->resendVerificationEmail('any@example.com');
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

test('changeEmail() throws NotLoggedInException when not logged in', function (): void {
    clearSession();
    $service = bootAuthLayer();

    expect(fn() => $service->changeEmail('new@example.com'))
        ->toThrow(Delight\Auth\NotLoggedInException::class);
});

// ---------------------------------------------------------------------------
// Coverage added for the AuthService split in Phase 3 PR 3.4. The methods
// exercised here will move into AuthEmailFlow / AuthRoleAdmin; these tests
// pin the public contract so the split is a pure refactor (php:S1448).
// ---------------------------------------------------------------------------

describe('AuthService::changeEmail', function (): void {
    test('logged-in user can request an email change and the verification URL follows the app URL', function (): void {
        $service = bootAuthLayer();
        bootAuth($service, 'change-loggedin@example.com');

        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);
        $service->setAppUrl('https://spora.test');

        $service->changeEmail('change-target@example.com');

        expect($captured['verify'])->toBeString();
        expect($captured['verify'])->toStartWith('https://spora.test/auth/verify/');
    });

    test('logged-out request fails with NotLoggedInException', function (): void {
        clearSession();
        $service = bootAuthLayer();
        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        expect(fn() => $service->changeEmail('new@example.com'))
            ->toThrow(Delight\Auth\NotLoggedInException::class);

        // The callback must not fire when the request is rejected.
        expect($captured['verify'])->toBeNull();
    });

    test('changeEmail callback receives a URL containing a token parameter', function (): void {
        $service = bootAuthLayer();
        bootAuth($service, 'change-token@example.com');

        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        $service->changeEmail('change-target-token@example.com');

        expect($captured['verify'])->toMatch('#\?token=.+#');
    });
});

describe('AuthService::forgotPassword', function (): void {
    test('triggers a password-reset email for an existing user', function (): void {
        $service = bootAuthLayer();
        $service->register('forgot-existing@example.com', 'ValidPass1!', 'Forgot Existing');

        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        $service->forgotPassword('forgot-existing@example.com');

        expect($captured['reset'])->toBeString();
        expect($captured['reset'])->toContain('/auth/reset-password/');
        expect($captured['reset'])->toMatch('#\?token=.+#');
    });

    test('throws InvalidEmailException for a non-existent user (mirrors delight-im, prevents silent enumeration leak)', function (): void {
        $service = bootAuthLayer();
        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        // The current implementation does not catch InvalidEmailException, so
        // unknown addresses surface as a typed exception. The AuthController
        // layer is responsible for the "no enumeration" guarantee.
        expect(fn() => $service->forgotPassword('ghost@example.com'))
            ->toThrow(Delight\Auth\InvalidEmailException::class);

        expect($captured['reset'])->toBeNull();
    });
});

describe('AuthService::resendVerificationEmail', function (): void {
    test('sends a verification email when a confirmation request is pending', function (): void {
        $service = bootAuthLayer();

        // Wire the mailer BEFORE register so the user is created unverified
        // and an open confirmation request is recorded by delight-im.
        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        $service->register('resend-unverified@example.com', 'ValidPass1!', 'Resend Unverified');

        $service->resendVerificationEmail('resend-unverified@example.com');

        expect($captured['verify'])->toBeString();
        expect($captured['verify'])->toContain('/auth/verify/');
    });

    test('does nothing for a verified user with no pending confirmation', function (): void {
        $service = bootAuthLayer();
        // Registering without a system mailer auto-verifies the user, so no
        // confirmation request exists in users_confirmations.
        $service->register('resend-verified@example.com', 'ValidPass1!', 'Resend Verified');

        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        $service->resendVerificationEmail('resend-verified@example.com');

        // ConfirmationRequestNotFound is caught internally; callback never fires.
        expect($captured['verify'])->toBeNull();
    });
});

describe('AuthService::grantRole / ::revokeRole', function (): void {
    test('grantRole persists the role on the user', function (): void {
        $service = bootAuthLayer();
        $userId = $service->register('grant-persist@example.com', 'ValidPass1!', 'Grant Persist');

        $service->grantRole($userId, Role::ADMIN);

        $user = User::find($userId);
        expect($user)->not->toBeNull();
        expect($user->hasRole(Role::ADMIN))->toBeTrue();
    });

    test('revokeRole removes the role from the user', function (): void {
        $service = bootAuthLayer();
        $userId = $service->register('revoke-persist@example.com', 'ValidPass1!', 'Revoke Persist');
        $service->grantRole($userId, Role::ADMIN);

        $service->revokeRole($userId, Role::ADMIN);

        $user = User::find($userId);
        expect($user)->not->toBeNull();
        expect($user->hasRole(Role::ADMIN))->toBeFalse();
    });

    test('userHasRole returns the right value across grant and revoke', function (): void {
        $service = bootAuthLayer();
        $userId = $service->register('hasrole-flow@example.com', 'ValidPass1!', 'HasRole Flow');

        expect($service->userHasRole($userId, Role::ADMIN))->toBeFalse();

        $service->grantRole($userId, Role::ADMIN);
        expect($service->userHasRole($userId, Role::ADMIN))->toBeTrue();

        $service->revokeRole($userId, Role::ADMIN);
        expect($service->userHasRole($userId, Role::ADMIN))->toBeFalse();
    });
});

describe('AuthService typed exception flow', function (): void {
    test('register with a duplicate email throws EmailTakenException', function (): void {
        $service = bootAuthLayer();
        $service->register('dup-email@example.com', 'ValidPass1!', 'Dup Email');

        expect(fn() => $service->register('dup-email@example.com', 'ValidPass1!', 'Dup Email'))
            ->toThrow(EmailTakenException::class);
    });

    test('login with bad credentials throws InvalidCredentialsException', function (): void {
        $service = bootAuthLayer();
        $service->register('bad-creds@example.com', 'ValidPass1!', 'Bad Creds');

        expect(fn() => $service->login('bad-creds@example.com', 'WrongPassword1!'))
            ->toThrow(InvalidCredentialsException::class);
    });
});

// Smoke test added for the AuthService split in Phase 3 PR 3.4. The facade
// must wire up the two new collaborators (AuthEmailFlow, AuthRoleAdmin) so
// that delegated calls reach the right collaborator (php:S1448).
// ---------------------------------------------------------------------------

test('AuthService wires AuthEmailFlow and AuthRoleAdmin collaborators (split smoke test)', function (): void {
    $service = bootAuthLayer();

    $reflection = new ReflectionObject($service);
    $emailFlow  = $reflection->getProperty('emailFlow')->getValue($service);
    $roleAdmin  = $reflection->getProperty('roleAdmin')->getValue($service);

    expect($emailFlow)->toBeInstanceOf(Spora\Auth\AuthEmailFlow::class);
    expect($roleAdmin)->toBeInstanceOf(Spora\Auth\AuthRoleAdmin::class);
});

test('setSystemMailer forwards the mailer to the AuthEmailFlow collaborator', function (): void {
    $service = bootAuthLayer();
    $mailer  = new SystemMailer(['mail_driver' => 'log']);

    $service->setSystemMailer($mailer);

    $flowProp  = (new ReflectionObject($service))->getProperty('emailFlow');
    $flowValue = $flowProp->getValue($service);
    $mailerProp = (new ReflectionObject($flowValue))->getProperty('systemMailer');
    expect($mailerProp->getValue($flowValue))->toBe($mailer);
});
