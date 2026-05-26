<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Interface for system mailer operations used by AuthService.
 * Allows for easy mocking in tests.
 */
interface MailerInterface
{
    public function sendPasswordResetEmail(string $email, string $resetUrl): bool;
    public function sendVerificationEmail(int $userId, string $email, string $verificationUrl): bool;
    public function sendWelcomeEmail(int $userId, string $email): bool;
}
