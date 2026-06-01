<?php

declare(strict_types=1);

namespace Spora\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CsrfTokenService
{
    private const TOKEN_BYTES = 32;
    private const SESSION_KEY = 'csrf_token';

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function generate(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    public function getToken(): ?string
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /** Returns the token (creates one if needed — works for remember-me sessions). */
    public function getOrCreateToken(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $this->logger->debug('CSRF token not found in session, generating lazily');
            $this->generate();
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function validate(string $token): bool
    {
        $sessionToken = $this->getToken();

        if ($sessionToken === null) {
            $this->logger->warning('CSRF validation failed: no token in session');
            return false;
        }

        $valid = hash_equals($sessionToken, $token);
        if (!$valid) {
            $this->logger->warning('CSRF validation failed: token mismatch');
        }

        return $valid;
    }

    public function regenerate(): string
    {
        $this->logger->info('CSRF token regenerated');
        $this->invalidate();

        return $this->generate();
    }

    public function invalidate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
