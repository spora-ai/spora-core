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

        if ($token === '') {
            $this->logger->warning('CSRF token missing for request', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'ip' => $request->getClientIp(),
            ]);
            return new JsonResponse(
                ['error' => ['code' => 'CSRF_TOKEN_MISSING', 'message' => 'CSRF token is required.']],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (!$this->csrfService->validate($token)) {
            $this->logger->warning('CSRF token invalid for request', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'ip' => $request->getClientIp(),
            ]);
            return new JsonResponse(
                ['error' => ['code' => 'CSRF_INVALID', 'message' => 'CSRF token is invalid.']],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
