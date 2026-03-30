<?php

declare(strict_types=1);

namespace Spora\Auth;

use Delight\Auth\Auth;
use Delight\Auth\EmailNotVerifiedException;
use Delight\Auth\InvalidEmailException;
use Delight\Auth\InvalidPasswordException;
use Delight\Auth\UserAlreadyExistsException;
use Delight\Auth\UserSuspendedException;
use InvalidArgumentException;
use Spora\Auth\Exceptions\AccountSuspendedException;
use Spora\Auth\Exceptions\AccountUnverifiedException;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Auth\Exceptions\InvalidCredentialsException;

/**
 * Thin wrapper around delight-im/Auth that exposes a typed, vendor-agnostic API.
 * All delight-im exceptions are caught here and re-thrown as Spora domain exceptions
 * so no other class needs to import delight-im types.
 */
final class AuthService
{
    public function __construct(private readonly Auth $auth) {}

    /**
     * Register a new user and return their new user ID.
     *
     * @throws InvalidArgumentException if the email or password is invalid
     * @throws EmailTakenException       if a user with that email already exists
     */
    public function register(string $email, string $password): int
    {
        try {
            return (int) $this->auth->register($email, $password, null, null);
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
     * @throws AccountSuspendedException    if the account has been suspended
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
        } catch (UserSuspendedException) {
            throw new AccountSuspendedException('This account has been suspended.');
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
        $id = $this->auth->getUserId();

        return $id !== null ? (int) $id : null;
    }

    /**
     * Return the email address of the currently authenticated user, or null if not logged in.
     */
    public function currentUserEmail(): ?string
    {
        return $this->auth->getEmail();
    }
}
