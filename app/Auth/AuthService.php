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
 * Thin facade over delight-im/auth exposing a typed, vendor-agnostic API.
 * All delight-im exceptions are caught here and re-thrown as Spora domain exceptions
 * so no other class needs to import delight-im types.
 *
 * Concerns are split across two collaborators:
 *  - {@see AuthEmailFlow} — change email, password reset, email verification, welcome email
 *  - {@see AuthRoleAdmin} — role and user-account administration
 *
 * This class is the entry point used by HTTP controllers; the collaborators are
 * not part of the public API. Splitting keeps this facade under the S1448
 * (≤20 methods) limit (php:S1448).
 *
 * Not declared `final` because Mockery needs to construct a named mock
 * for HTTP-handler tests (e.g. {@see \Tests\Feature\AssetControllerTest}).
 * Subclassing is still discouraged — instantiate via the container.
 */
class AuthService
{
    private readonly AuthEmailFlow $emailFlow;
    private readonly AuthRoleAdmin $roleAdmin;

    public function __construct(private readonly Auth $auth)
    {
        $this->emailFlow = new AuthEmailFlow($auth);
        $this->roleAdmin = new AuthRoleAdmin($auth);
    }

    public function setSystemMailer(MailerInterface $systemMailer): void
    {
        $this->emailFlow->setSystemMailer($systemMailer);
    }

    public function setAppUrl(string $url): void
    {
        $this->emailFlow->setAppUrl($url);
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
            $verifyCallback = $this->emailFlow->buildVerificationCallback($email);

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
     * Request an email address change for the currently authenticated user.
     *
     * @throws InvalidEmailException if the new email is invalid
     * @throws UserAlreadyExistsException if the new email is already taken
     * @throws \Delight\Auth\NotLoggedInException if no user is logged in
     * @throws EmailNotVerifiedException if the current email is not verified
     */
    public function changeEmail(string $newEmail): void
    {
        $this->emailFlow->changeEmail($newEmail);
    }

    /**
     * Confirm an email address using a selector/token pair.
     * After successful confirmation, sends the welcome email if SPORA_SEND_WELCOME_EMAIL is enabled.
     *
     * @return array{0: string, 1: string} [old_email, new_email]
     */
    public function confirmEmail(string $selector, string $token): array
    {
        return $this->emailFlow->confirmEmail($selector, $token);
    }

    /**
     * Initiate a password reset for the given email address.
     */
    public function forgotPassword(string $email): void
    {
        $this->emailFlow->forgotPassword($email);
    }

    /**
     * Reset the password using a selector/token pair.
     */
    public function resetPassword(string $selector, string $token, string $newPassword): void
    {
        $this->emailFlow->resetPassword($selector, $token, $newPassword);
    }

    /**
     * Resend the email verification email for an unverified user.
     * Silently returns if there is no pending confirmation request for the email.
     */
    public function resendVerificationEmail(string $email): void
    {
        $this->emailFlow->resendVerificationEmail($email);
    }

    /**
     * Grant a role to a user.
     */
    public function grantRole(int $userId, int $role): void
    {
        $this->roleAdmin->grantRole($userId, $role);
    }

    /**
     * Revoke a role from a user.
     */
    public function revokeRole(int $userId, int $role): void
    {
        $this->roleAdmin->revokeRole($userId, $role);
    }

    /**
     * Check if a user has a specific role.
     */
    public function userHasRole(int $userId, int $role): bool
    {
        return $this->roleAdmin->userHasRole($userId, $role);
    }

    /**
     * Delete a user by ID.
     */
    public function deleteUser(int $userId): void
    {
        $this->roleAdmin->deleteUser($userId);
    }
}
