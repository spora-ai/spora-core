<?php

declare(strict_types=1);

namespace Spora\Auth;

use Delight\Auth\Auth;
use Delight\Auth\EmailNotVerifiedException;
use Delight\Auth\InvalidEmailException;
use Delight\Auth\NotLoggedInException;
use Delight\Auth\UserAlreadyExistsException;
use Spora\Models\User;
use Spora\Services\MailerInterface;

/**
 * Email-driven authentication flows: email verification, email change,
 * password reset, and the welcome email. Extracted from {@see AuthService}
 * to keep that class under the S1448 (≤20 methods) limit (php:S1448).
 *
 * The facade {@see AuthService} owns the system mailer + app URL configuration
 * and forwards the relevant bits here. This class never throws vendor-agnostic
 * domain exceptions — delight-im exceptions bubble up to the facade.
 */
final class AuthEmailFlow
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

    /**
     * Build a verification-email callback for delight-im/auth. Returns null when
     * no system mailer is wired up, letting the upstream caller skip the callback
     * (delight-im treats a null callback as "no email sent").
     *
     * @return (callable(string, string): void)|null
     */
    public function buildVerificationCallback(string $email, ?string $customVerifyPath = '/auth/verify/'): ?callable
    {
        return $this->systemMailer !== null
            ? $this->sendVerificationEmailViaCallback($email, $customVerifyPath)
            : null;
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
     * @throws NotLoggedInException if no user is logged in
     * @throws EmailNotVerifiedException if the current email is not verified
     */
    public function changeEmail(string $newEmail): void
    {
        if ($this->systemMailer === null) {
            // No system mailer wired up — delight-im's changeEmail() still needs
            // a callback to fire, but we explicitly don't want to send a
            // verification email. An empty closure is the documented way to
            // skip that step without disabling the change itself.
            $this->auth->changeEmail($newEmail, static function (): void {
                // intentionally empty — see comment above
            });
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

    /**
     * @return callable(string, string): void
     */
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
}
