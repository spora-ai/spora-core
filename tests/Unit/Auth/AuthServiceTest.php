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
    $captured = new ArrayObject(['verify' => null, 'reset' => null, 'change_verify' => null, 'welcome' => null]);

    $mailer = new class ($captured) implements MailerInterface {
        /** @var ArrayObject */
        private ArrayObject $captured;

        public function __construct(ArrayObject $captured)
        {
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

        public function sendEmailChangeVerificationEmail(string $email, string $verificationUrl): bool
        {
            $this->captured['change_verify'] = $verificationUrl;

            return true;
        }

        public function sendWelcomeEmail(int $userId, string $email): bool
        {
            $this->captured['welcome'] = ['user_id' => $userId, 'email' => $email];

            return true;
        }
    };

    return [$mailer, $captured];
}

test('register() with an invalid email format throws InvalidArgumentException', function (): void {
    $service = bootAuthLayer();

    expect(fn() => $service->register('not-an-email', 'ValidPass1!', 'Not An Email'))->toThrow(InvalidArgumentException::class);
});

test('register() with $markAsVerified true persists verified=1 and skips the verification email', function (): void {
    $service = bootAuthLayer();
    [$mailer, $captured] = makeCapturingMailer();
    $service->setSystemMailer($mailer);

    $email = 'bootstrap-admin@example.com';
    $userId = $service->register($email, 'ValidPass1!', 'Bootstrap', true);

    $user = User::where('email', $email)->firstOrFail();
    expect($user->id)->toBe($userId)
        ->and($user->verified)->toBe(1)
        ->and($captured['verify'])->toBeNull();
});

test('register() without $markAsVerified leaves the user unverified and sends the verification email', function (): void {
    $service = bootAuthLayer();
    [$mailer, $captured] = makeCapturingMailer();
    $service->setSystemMailer($mailer);

    $service->register('self-signup@example.com', 'ValidPass1!', 'Self Signup');

    $user = User::where('email', 'self-signup@example.com')->firstOrFail();
    expect($user->verified)->toBe(0)
        ->and($captured['verify'])->toBeString()
        ->and($captured['verify'])->not->toBe('');
});

test('login() works without a confirmation step when the user was registered with $markAsVerified', function (): void {
    $service = bootAuthLayer();
    [$mailer] = makeCapturingMailer();
    $service->setSystemMailer($mailer);

    $email = 'no-confirm@example.com';
    $service->register($email, 'ValidPass1!', 'No Confirm', true);
    clearSession();

    $service->login($email, 'ValidPass1!');

    expect($service->currentUserEmail())->toBe($email);
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

test('confirmEmail() sends the welcome email for an initial-signup confirmation (v9 null-old contract)', function (): void {
    $service = bootAuthLayer();
    $userId = $service->register('welcome-ok@example.com', 'Password1!', 'Welcome OK');

    [$mailer, $captured] = makeCapturingMailer();
    $service->setSystemMailer($mailer);

    $selector = 'wco' . bin2hex(random_bytes(8));
    $rawToken = 'tok' . bin2hex(random_bytes(8));
    $hashedToken = Delight\Auth\TokenHash::from($rawToken);

    // Confirmation row whose email matches the user's current email —
    // delight-im collapses $oldEmail to null in this case.
    Capsule::table('users_confirmations')->insert([
        'user_id' => (int) $userId,
        'email'    => 'welcome-ok@example.com',
        'selector' => $selector,
        'token'    => $hashedToken,
        'expires'  => time() + 86400,
    ]);

    $_ENV['SPORA_SEND_WELCOME_EMAIL'] = 'true';
    try {
        [$oldEmail, $newEmail] = $service->confirmEmail($selector, $rawToken);
    } finally {
        unset($_ENV['SPORA_SEND_WELCOME_EMAIL']);
    }

    expect($oldEmail)->toBeNull();
    expect($newEmail)->toBe('welcome-ok@example.com');
    expect($captured['welcome']['user_id'])->toBe($userId);
    expect($captured['welcome']['email'])->toBe('welcome-ok@example.com');
});

test('confirmEmail() does NOT send the welcome email for an address-change confirmation (old != null)', function (): void {
    $service = bootAuthLayer();
    $userId = $service->register('welcome-skip@example.com', 'Password1!', 'Welcome Skip');
    User::where('id', $userId)->update(['verified' => 1]);

    [$mailer, $captured] = makeCapturingMailer();
    $service->setSystemMailer($mailer);

    $selector = 'wsk' . bin2hex(random_bytes(8));
    $rawToken = 'tok' . bin2hex(random_bytes(8));
    $hashedToken = Delight\Auth\TokenHash::from($rawToken);

    // Confirmation row pointing at a NEW email — delight-im returns the
    // previous email as $oldEmail, which is non-null.
    Capsule::table('users_confirmations')->insert([
        'user_id' => (int) $userId,
        'email'    => 'welcome-skip-new@example.com',
        'selector' => $selector,
        'token'    => $hashedToken,
        'expires'  => time() + 86400,
    ]);

    $_ENV['SPORA_SEND_WELCOME_EMAIL'] = 'true';
    try {
        [$oldEmail, $newEmail] = $service->confirmEmail($selector, $rawToken);
    } finally {
        unset($_ENV['SPORA_SEND_WELCOME_EMAIL']);
    }

    expect($oldEmail)->toBe('welcome-skip@example.com');
    expect($newEmail)->toBe('welcome-skip-new@example.com');
    expect($captured['welcome'])->toBeNull();
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
        $service = bootAuthLayer('https://spora.test', '');
        bootAuth($service, 'change-loggedin@example.com');

        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        $service->changeEmail('change-target@example.com');

        expect($captured['change_verify'])->toBeString();
        expect($captured['change_verify'])->toStartWith('https://spora.test/auth/verify/');
        // Signup-template callback must not have fired for the change flow.
        expect($captured['verify'])->toBeNull();
    });

    test('constructor appPrefix prepends the path prefix to the verification URL', function (): void {
        $service = bootAuthLayer('https://spora.fabiangrassl.de', '/spora');
        bootAuth($service, 'change-prefix@example.com');

        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        $service->changeEmail('change-prefix-target@example.com');

        expect($captured['change_verify'])->toBeString();
        expect($captured['change_verify'])->toStartWith('https://spora.fabiangrassl.de/spora/auth/verify/');
        expect($captured['change_verify'])->not->toStartWith('https://spora.fabiangrassl.de/spora/spora/');
        expect($captured['verify'])->toBeNull();
    });

    test('logged-out request fails with NotLoggedInException', function (): void {
        clearSession();
        $service = bootAuthLayer();
        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        expect(fn() => $service->changeEmail('new@example.com'))
            ->toThrow(Delight\Auth\NotLoggedInException::class);

        // The callback must not fire when the request is rejected.
        expect($captured['change_verify'])->toBeNull();
        expect($captured['verify'])->toBeNull();
    });

    test('changeEmail callback receives a URL containing a token parameter', function (): void {
        $service = bootAuthLayer();
        bootAuth($service, 'change-token@example.com');

        [$mailer, $captured] = makeCapturingMailer();
        $service->setSystemMailer($mailer);

        $service->changeEmail('change-target-token@example.com');

        expect($captured['change_verify'])->toMatch('#\?token=.+#');
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
