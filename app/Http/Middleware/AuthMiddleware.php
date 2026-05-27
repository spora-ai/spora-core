<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Spora\Auth\AuthService;
use Spora\Http\Exceptions\UnauthenticatedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->authService->isLoggedIn()) {
            throw new UnauthenticatedException('Authentication required.');
        }

        return $next($request);
    }
}
