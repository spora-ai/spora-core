<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTime;
use Delight\Auth\AuthException;
use InvalidArgumentException;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Auth\Exceptions\AccountUnverifiedException;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Auth\Exceptions\InvalidCredentialsException;
use Spora\Security\CsrfTokenService;
use Spora\Services\AuthValidator;
use Spora\Services\RateLimiter;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles authentication: registration, login, logout, password reset, and email verification.
 *
 * Each public method follows the early-exit-guard + helper pattern (see TaskController
 * for the canonical example). The public method handles auth checks, JSON decoding, and
 * field-level validation, then delegates to a private helper that owns the try/catch
 * block mapping delight-im exceptions to JSON responses. This keeps every method to
 * at most three `return` statements to satisfy SonarQube S1142.
 *
 * JSON decoding, field validation, and exception → JsonResponse mapping live in
 * {@see AuthValidator} so the controller stays under the S1448 (≤20 methods) limit.
 */
final class AuthController
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    private const MSG_EMAIL_REQUIRED = 'The field "email" is required.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserServiceInterface $userService,
        private readonly CsrfTokenService $csrfService,
        private readonly AuthValidator $validator,
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

        return $this->handleRegister($request, $clientIp);
    }

    public function login(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        return $this->handleLogin($request, $clientIp);
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

        return $this->buildMeResponse($userId);
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

        return $this->performPasswordChange($body);
    }

    public function account(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->validator->unauthenticated();
        }

        return $this->updateAccountAndRespond($userId, $request);
    }

    public function verify(Request $request, string $selector): JsonResponse
    {
        $token = $request->query->get('token', '');

        if ($selector === '' || $token === '') {
            return $this->validator->error('VALIDATION_ERROR', 'The selector and token are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->performEmailVerification($selector, $token);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        return $this->handleForgotPassword($request, $clientIp);
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

        return $this->performPasswordReset($body);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        return $this->handleResendVerification($request, $clientIp);
    }

    public function requestEmailChange(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->validator->unauthenticated();
        }

        return $this->handleEmailChangeRequest($request);
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

        return $this->performEmailChangeConfirmation($body);
    }

    // ---------------------------------------------------------------------
    // Helper methods — each owns one slice of the workflow to keep the
    // public methods (and themselves) under the S1142 3-return limit.
    // ---------------------------------------------------------------------

    /**
     * Decode the body, then validate the registration payload. Delegates the
     * delight-im call to {@see performRegister()} so the try/catch lives in
     * exactly one place.
     */
    private function handleRegister(Request $request, string $clientIp): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validation = $this->validateRegisterFields($body);
        if ($validation !== null) {
            return $validation;
        }

        return $this->performRegister($body, $clientIp);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateRegisterFields(array $body): ?JsonResponse
    {
        if ($this->validator->missingFields($body, ['email', 'password', 'display_name', 'confirm_password'])) {
            return $this->validator->error('VALIDATION_ERROR', 'The fields "email", "password", "display_name", and "confirm_password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($body['password'] !== $body['confirm_password']) {
            return $this->validator->error('VALIDATION_ERROR', 'Passwords do not match.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function performRegister(array $body, string $clientIp): JsonResponse
    {
        try {
            $userId = $this->authService->register((string) $body['email'], (string) $body['password'], (string) $body['display_name']);
        } catch (EmailTakenException) {
            RateLimiter::clear($clientIp);

            return $this->validator->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (InvalidArgumentException $e) {
            return $this->validator->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->withRateLimitHeaders(
            new JsonResponse(
                ['data' => [
                    'user' => ['id' => $userId, 'email' => $body['email']],
                    'csrf_token' => $this->csrfService->regenerate(),
                ]],
                Response::HTTP_CREATED,
            ),
            $clientIp,
        );
    }

    private function handleLogin(Request $request, string $clientIp): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->validator->missingFields($body, ['email', 'password'])) {
            return $this->validator->error('VALIDATION_ERROR', 'The fields "email" and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->performLogin($body, $clientIp);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function performLogin(array $body, string $clientIp): JsonResponse
    {
        try {
            $this->authService->login((string) $body['email'], (string) $body['password'], (bool) ($body['remember_me'] ?? false));
            RateLimiter::clear($clientIp);
        } catch (InvalidCredentialsException) {
            return $this->validator->error('INVALID_CREDENTIALS', 'The email address or password is incorrect.', Response::HTTP_UNAUTHORIZED);
        } catch (AccountUnverifiedException) {
            return $this->validator->error('ACCOUNT_UNVERIFIED', 'Please verify your email address before logging in.', Response::HTTP_FORBIDDEN);
        }

        $userId = $this->authService->currentUserId();
        $result = $this->userService->getUser($userId);
        $userData = $result !== null ? $result['user'] : ['id' => $userId, 'email' => (string) $body['email'], 'username' => null];

        return $this->withRateLimitHeaders(
            new JsonResponse(
                ['data' => [
                    'user' => $userData,
                    'csrf_token' => $this->csrfService->regenerate(),
                ]],
                Response::HTTP_OK,
            ),
            $clientIp,
        );
    }

    private function buildMeResponse(int $userId): JsonResponse
    {
        $result = $this->userService->getUser($userId);

        if ($result === null) {
            return $this->validator->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        $user = $result['user'];
        $registeredTimestamp = $user['registered'] ?? time();
        $registered = (new DateTime())->setTimestamp((int) $registeredTimestamp)->format(DateTime::ATOM);

        return new JsonResponse(
            ['data' => ['user' => [
                'id'         => $user['id'],
                'email'      => $user['email'],
                'name'       => $user['name'],
                'roles'      => $user['roles'] ?? [],
                'registered' => $registered,
                'is_admin'   => in_array('ADMIN', $user['roles'] ?? [], true),
            ], 'csrf_token' => $this->csrfService->getOrCreateToken()]],
            Response::HTTP_OK,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function performPasswordChange(array $body): JsonResponse
    {
        try {
            $this->authService->changePassword((string) $body['current_password'], (string) $body['new_password']);
        } catch (AuthException $e) {
            return $this->validator->mapPasswordChangeError($e);
        }

        return new JsonResponse(['message' => 'Password updated'], Response::HTTP_OK);
    }

    private function updateAccountAndRespond(int $userId, Request $request): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $result = $this->userService->updateUser($userId, $body);
        if ($result === null) {
            return $this->validator->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(
            ['data' => $result],
            Response::HTTP_OK,
        );
    }

    private function performEmailVerification(string $selector, string $token): JsonResponse
    {
        try {
            $this->authService->confirmEmail($selector, $token);
        } catch (AuthException $e) {
            return $this->validator->mapEmailVerificationError($e);
        }

        return new JsonResponse(['message' => 'Email verified successfully.'], Response::HTTP_OK);
    }

    private function handleForgotPassword(Request $request, string $clientIp): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->validator->missingFields($body, ['email'])) {
            return $this->validator->error('VALIDATION_ERROR', self::MSG_EMAIL_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->authService->forgotPassword((string) $body['email']);

        // Count the attempt even on success to prevent brute-forcing valid emails
        RateLimiter::attempt($clientIp, self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_WINDOW_SECONDS);

        return new JsonResponse(['message' => 'If an account with that email exists, a password reset email has been sent.'], Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function performPasswordReset(array $body): JsonResponse
    {
        try {
            $this->authService->resetPassword((string) $body['selector'], (string) $body['token'], (string) $body['password']);
        } catch (AuthException $e) {
            return $this->validator->mapPasswordResetError($e);
        }

        return new JsonResponse(['message' => 'Password reset successfully.'], Response::HTTP_OK);
    }

    private function handleResendVerification(Request $request, string $clientIp): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->validator->missingFields($body, ['email'])) {
            return $this->validator->error('VALIDATION_ERROR', self::MSG_EMAIL_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->resendVerificationEmail((string) $body['email']);
            RateLimiter::clear($clientIp);
        } catch (\Delight\Auth\EmailNotVerifiedException) {
            // Silently succeed — we don't reveal whether the email is verified
        } catch (\Delight\Auth\InvalidEmailException) {
            // Silently succeed
        }

        // Always return success to prevent email enumeration
        return new JsonResponse(['message' => 'If an account with that email exists and is unverified, a verification email has been sent.'], Response::HTTP_OK);
    }

    private function handleEmailChangeRequest(Request $request): JsonResponse
    {
        $body = $this->validator->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        if ($this->validator->missingFields($body, ['email'])) {
            return $this->validator->error('VALIDATION_ERROR', self::MSG_EMAIL_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->performEmailChangeRequest($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function performEmailChangeRequest(array $body): JsonResponse
    {
        try {
            $this->authService->changeEmail((string) $body['email']);
        } catch (AuthException $e) {
            return $this->validator->mapEmailChangeRequestError($e);
        }

        return new JsonResponse(['message' => 'A confirmation email has been sent to your new email address.'], Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function performEmailChangeConfirmation(array $body): JsonResponse
    {
        try {
            $this->authService->confirmEmail((string) $body['selector'], (string) $body['token']);
        } catch (AuthException $e) {
            return $this->validator->mapEmailChangeConfirmationError($e);
        }

        return new JsonResponse(['message' => 'Email address changed successfully.'], Response::HTTP_OK);
    }

    // ---------------------------------------------------------------------
    // HTTP-layer helpers — kept here because they own request-header and
    // client-IP concerns that don't belong in the validator.
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
