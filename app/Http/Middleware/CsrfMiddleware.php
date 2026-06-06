<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Security\CsrfTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly CsrfTokenService $csrfService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->headers->get('X-CSRF-Token', '');

        $rejection = $this->validateCsrfToken($token, $request);
        if ($rejection instanceof JsonResponse) {
            return $rejection;
        }

        return $next($request);
    }

    private function validateCsrfToken(string $token, Request $request): ?JsonResponse
    {
        if ($token === '') {
            $this->logCsrfRejection('CSRF token missing for request', $request);
            return new JsonResponse(
                ['error' => ['code' => 'CSRF_TOKEN_MISSING', 'message' => 'CSRF token is required.']],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (!$this->csrfService->validate($token)) {
            $this->logCsrfRejection('CSRF token invalid for request', $request);
            return new JsonResponse(
                ['error' => ['code' => 'CSRF_INVALID', 'message' => 'CSRF token is invalid.']],
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    private function logCsrfRejection(string $message, Request $request): void
    {
        $this->logger->warning($message, [
            'method' => $request->getMethod(),
            'path'   => $request->getPathInfo(),
            'ip'     => $request->getClientIp(),
        ]);
    }
}
