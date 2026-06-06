<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Security\CsrfTokenService;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles authentication: registration, login, logout, password reset, and email verification.
 *
 * Each public method follows the early-exit-guard + delegation pattern. The public
 * method handles auth checks, JSON decoding, rate-limit guards, and field-level
 * validation, then delegates to {@see AuthWorkflow} which owns the try/catch
 * block mapping delight-im exceptions to JSON responses. This keeps every method
 * to at most three `return` statements to satisfy SonarQube S1142.
 *
 * JSON decoding, field validation, and exception → JsonResponse mapping live in
 * {@see AuthValidator}; the workflow helpers extracted from this class live in
 * {@see AuthWorkflow}. Together they keep the controller under the S1448
 * (≤20 methods) limit.
 */
final class AuthController
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly AuthService $authService,
        private readonly CsrfTokenService $csrfService,
        private readonly AuthValidator $validator,
        private readonly AuthWorkflow $workflow,
        private readonly array $config = [],
    ) {}

    public function register(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        if (!($this->config['allow_registration'] ?? true)) {
            return $this->validator->error('REGISTRATION_DISABLED', 'Registration is currently disabled.', Response::HTTP_FORBIDDEN);
        }

        return $this->withRateLimitHeaders(
            $this->workflow->handleRegister($request, $clientIp),
            $clientIp,
        );
    }

    public function login(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        return $this->withRateLimitHeaders(
            $this->workflow->handleLogin($request, $clientIp),
            $clientIp,
        );
    }

    public function logout(): JsonResponse
    {
        $this->csrfService->invalidate();
        $this->authService->logout();

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    public function me(): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($userId === null) {
            return $this->validator->unauthenticated();
        }

        return $this->workflow->buildMeResponse($userId);
    }

    public function password(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->validator->unauthenticated();
        }
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->validator->invalidJson();
        }
        if ($this->validator->missingFields($body, ['current_password', 'new_password'])) {
            return $this->validator->error('VALIDATION_ERROR', 'The fields "current_password" and "new_password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->workflow->performPasswordChange($body);
    }

    public function account(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->validator->unauthenticated();
        }

        return $this->workflow->updateAccountAndRespond($userId, $request);
    }

    public function verify(Request $request, string $selector): JsonResponse
    {
        $token = $request->query->get('token', '');

        if ($selector === '' || $token === '') {
            return $this->validator->error('VALIDATION_ERROR', 'The selector and token are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->workflow->performEmailVerification($selector, $token);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        return $this->workflow->handleForgotPassword($request, $clientIp);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->validator->missingFields($body, ['selector', 'token', 'password'])) {
            return $this->validator->error('VALIDATION_ERROR', 'The fields "selector", "token", and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->workflow->performPasswordReset($body);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        return $this->workflow->handleResendVerification($request, $clientIp);
    }

    public function requestEmailChange(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->validator->unauthenticated();
        }

        return $this->workflow->handleEmailChangeRequest($request);
    }

    public function confirmEmailChange(Request $request): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->validator->missingFields($body, ['selector', 'token'])) {
            return $this->validator->error('VALIDATION_ERROR', 'The selector and token are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->workflow->performEmailChangeConfirmation($body);
    }

    // ---------------------------------------------------------------------
    // HTTP-layer helpers — kept here because they own request-header and
    // client-IP concerns that don't belong in the workflow or validator.
    // ---------------------------------------------------------------------

    private function getClientIp(Request $request): string
    {
        $serverVar = $request->server->get('HTTP_X_FORWARDED_FOR');
        if ($serverVar !== null) {
            $parts = explode(',', $serverVar, 2);

            return trim($parts[0]);
        }

        return $request->server->get('REMOTE_ADDR', '0.0.0.0');
    }

    private function isRateLimited(string $clientIp): bool
    {
        return RateLimiter::attempt($clientIp, self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_WINDOW_SECONDS);
    }

    private function rateLimitedResponse(string $clientIp): JsonResponse
    {
        $retryAfter = RateLimiter::retryAfter($clientIp, self::RATE_LIMIT_WINDOW_SECONDS);

        $response = new JsonResponse(
            ['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests. Please try again later.']],
            Response::HTTP_TOO_MANY_REQUESTS,
        );
        $response->headers->set('Retry-After', (string) $retryAfter);
        $response->headers->set('X-RateLimit-Limit', (string) self::RATE_LIMIT_MAX_ATTEMPTS);
        $response->headers->set('X-RateLimit-Remaining', '0');

        return $response;
    }

    private function withRateLimitHeaders(JsonResponse $response, string $clientIp): JsonResponse
    {
        $response->headers->set('X-RateLimit-Limit', (string) self::RATE_LIMIT_MAX_ATTEMPTS);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining(
            $clientIp,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS,
        ));

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
