<?php

declare(strict_types=1);

namespace Spora\Security;

final class CsrfTokenService
{
    private const TOKEN_BYTES = 32;
    private const SESSION_KEY = 'csrf_token';

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

    public function validate(string $token): bool
    {
        $sessionToken = $this->getToken();

        if ($sessionToken === null) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    public function regenerate(): string
    {
        $this->invalidate();

        return $this->generate();
    }

    public function invalidate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
