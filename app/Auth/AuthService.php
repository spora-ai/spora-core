<?php

declare(strict_types=1);

namespace Spora\Auth;

use Delight\Auth\Auth;
use Delight\Auth\EmailNotVerifiedException;
use Delight\Auth\InvalidEmailException;
use Delight\Auth\InvalidPasswordException;
use Delight\Auth\UserAlreadyExistsException;
use InvalidArgumentException;
use Spora\Auth\Exceptions\AccountUnverifiedException;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Auth\Exceptions\InvalidCredentialsException;
use Spora\Models\User;
use Spora\Services\MailerInterface;

/**
 * Thin wrapper around delight-im/Auth that exposes a typed, vendor-agnostic API.
 * All delight-im exceptions are caught here and re-thrown as Spora domain exceptions
 * so no other class needs to import delight-im types.
 */
final class AuthService
{
    private ?MailerInterface $systemMailer = null;
    private ?string $appUrl = null;

    public function __construct(private readonly Auth $auth) {}

    public function setSystemMailer(MailerInterface $systemMailer): void
    {
        $this->systemMailer = $systemMailer;
    }

    public function setAppUrl(string $url): void
    {
        $this->appUrl = $url;
    }

    private function sendVerificationEmailViaCallback(string $email, ?string $customVerifyPath = '/auth/verify/'): callable
    {
        return function (string $selector, string $token) use ($email, $customVerifyPath) {
            if ($this->systemMailer !== null) {
                $baseUrl = rtrim($this->appUrl ?? 'http://localhost', '/');
                $verifyUrl = "{$baseUrl}{$customVerifyPath}{$selector}?token=" . urlencode($token);
                $this->systemMailer->sendVerificationEmail($email, $verifyUrl);
            }
        };
    }

    /**
     * Grant a role to a user.
     */
    public function grantRole(int $userId, int $role): void
    {
        $this->auth->admin()->addRoleForUserById($userId, $role);
    }

    /**
     * Revoke a role from a user.
     */
    public function revokeRole(int $userId, int $role): void
    {
        $this->auth->admin()->removeRoleForUserById($userId, $role);
    }

    /**
     * Check if a user has a specific role.
     */
    public function userHasRole(int $userId, int $role): bool
    {
        return $this->auth->admin()->doesUserHaveRole($userId, $role);
    }

    /**
     * Delete a user by ID.
     */
    public function deleteUser(int $userId): void
    {
        $this->auth->admin()->deleteUserById($userId);
    }

    /**
     * Register a new user and return their new user ID.
     *
     * @throws InvalidArgumentException if the email or password is invalid
     * @throws EmailTakenException       if a user with that email already exists
     */
    public function register(string $email, string $password, string $displayName): int
    {
        try {
            $verifyCallback = $this->systemMailer !== null
                ? $this->sendVerificationEmailViaCallback($email)
                : null;

            $userId = (int) $this->auth->register($email, $password, null, $verifyCallback);

            $user = User::where('email', $email)->first();
            if ($user !== null) {
                $user->name = $displayName;
                $user->save();
            }

            return $userId;
        } catch (UserAlreadyExistsException) {
            throw new EmailTakenException('A user with that email address already exists.');
        } catch (InvalidEmailException) {
            throw new InvalidArgumentException('The provided email address is invalid.');
        } catch (InvalidPasswordException) {
            throw new InvalidArgumentException('The provided password does not meet the minimum requirements.');
        }
    }

    /**
     * Authenticate a user by email and password.
     * On success the session is populated by delight-im/auth.
     *
     * @param bool $rememberMe when true, keeps the user logged in across browser restarts
     *
     * @throws InvalidCredentialsException  if the email or password is incorrect
     * @throws AccountUnverifiedException   if the account requires email verification
     */
    public function login(string $email, string $password, bool $rememberMe = false): void
    {
        $rememberDuration = $rememberMe ? (int) (60 * 60 * 24 * 365.25) : null;

        try {
            $this->auth->login($email, $password, $rememberDuration);
        } catch (InvalidEmailException | InvalidPasswordException) {
            throw new InvalidCredentialsException('The email address or password is incorrect.');
        } catch (EmailNotVerifiedException) {
            throw new AccountUnverifiedException('Please verify your email address before logging in.');
        }
    }

    /**
     * Log the currently authenticated user out and destroy their session.
     */
    public function logout(): void
    {
        $this->auth->logOut();
    }

    /**
     * Return the ID of the currently authenticated user, or null if not logged in.
     */
    public function currentUserId(): ?int
    {
        if (!$this->auth->isLoggedIn()) {
            return null;
        }

        return (int) $this->auth->getUserId();
    }

    /**
     * Return true if a user is currently logged in.
     */
    public function isLoggedIn(): bool
    {
        return $this->auth->isLoggedIn();
    }

    /**
     * Return the email address of the currently authenticated user, or null if not logged in.
     */
    public function currentUserEmail(): ?string
    {
        if (!$this->auth->isLoggedIn()) {
            return null;
        }

        return $this->auth->getEmail();
    }

    public function isAdmin(): bool
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return false;
        }

        $user = (new User())->find($userId);
        return $user !== null && $user->isAdmin();
    }

    /**
     * Change the password of the currently authenticated user.
     *
     * @throws \Delight\Auth\NotLoggedInException if the user is not logged in
     * @throws InvalidPasswordException if the new password is invalid
     * @throws \Delight\Auth\AuthError if the old password is incorrect
     */
    public function changePassword(string $oldPassword, string $newPassword): void
    {
        $this->auth->changePassword($oldPassword, $newPassword);
    }

    /**
     * Confirm an email address using a selector/token pair.
     * After successful confirmation, sends the welcome email if SPORA_SEND_WELCOME_EMAIL is enabled.
     *
     * @return array{0: string, 1: string} [old_email, new_email]
     */
    public function confirmEmail(string $selector, string $token): array
    {
        $emails = $this->auth->confirmEmail($selector, $token);
        $oldEmail = $emails[0] ?? '';
        $newEmail = $emails[1] ?? '';

        $sendWelcomeEmail = (bool) ($_ENV['SPORA_SEND_WELCOME_EMAIL'] ?? false);
        // Only send welcome email for initial verification (where old email equals new email)
        if ($sendWelcomeEmail && $this->systemMailer !== null && $newEmail !== '' && $oldEmail === $newEmail) {
            $user = User::where('email', $newEmail)->first();
            if ($user !== null) {
                $this->systemMailer->sendWelcomeEmail((int) $user->id, $newEmail);
            }
        }

        return $emails;
    }

    /**
     * Request an email address change for the currently authenticated user.
     * Sends a confirmation email to the NEW address via the provided callback.
     *
     * @throws InvalidEmailException if the new email is invalid
     * @throws UserAlreadyExistsException if the new email is already taken
     * @throws \Delight\Auth\NotLoggedInException if no user is logged in
     * @throws EmailNotVerifiedException if the current email is not verified
     */
    public function changeEmail(string $newEmail): void
    {
        if ($this->systemMailer === null) {
            // No system mailer wired up — delight-im's changeEmail() still needs
            // a callback to fire, but we explicitly don't want to send a
            // verification email. An empty closure is the documented way to
            // skip that step without disabling the change itself.
            $this->auth->changeEmail($newEmail, static function (): void {});
            return;
        }

        $this->auth->changeEmail($newEmail, $this->sendVerificationEmailViaCallback($newEmail));
    }

    /**
     * Initiate a password reset for the given email address.
     */
    public function forgotPassword(string $email): void
    {
        $this->auth->forgotPassword($email, function (string $selector, string $token) use ($email): void {
            if ($this->systemMailer !== null) {
                $baseUrl = rtrim($this->appUrl ?? 'http://localhost', '/');
                $resetUrl = "{$baseUrl}/auth/reset-password/{$selector}?token=" . urlencode($token);
                $this->systemMailer->sendPasswordResetEmail($email, $resetUrl);
            }
        });
    }

    /**
     * Reset the password using a selector/token pair.
     */
    public function resetPassword(string $selector, string $token, string $newPassword): void
    {
        $this->auth->resetPassword($selector, $token, $newPassword);
    }

    /**
     * Resend the email verification email for an unverified user.
     * Silently returns if there is no pending confirmation request for the email.
     */
    public function resendVerificationEmail(string $email): void
    {
        if ($this->systemMailer === null) {
            return;
        }

        try {
            $this->auth->resendConfirmationForEmail($email, $this->sendVerificationEmailViaCallback($email));
        } catch (\Delight\Auth\ConfirmationRequestNotFound) {
            // No pending confirmation — nothing to resend; silently return
        }
    }
}
