<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTime;
use InvalidArgumentException;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Auth\Exceptions\AccountUnverifiedException;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Auth\Exceptions\InvalidCredentialsException;
use Spora\Security\CsrfTokenService;
use Spora\Services\RateLimiter;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserServiceInterface $userService,
        private readonly CsrfTokenService $csrfService,
        private readonly array $config = [],
    ) {}

    public function register(Request $request, array $vars = []): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        if (!($this->config['allow_registration'] ?? true)) {
            return $this->error('REGISTRATION_DISABLED', 'Registration is currently disabled.', Response::HTTP_FORBIDDEN);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['email', 'password', 'display_name', 'confirm_password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "email", "password", "display_name", and "confirm_password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($body['password'] !== $body['confirm_password']) {
            return $this->error('VALIDATION_ERROR', 'Passwords do not match.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $userId = $this->authService->register((string) $body['email'], (string) $body['password'], (string) $body['display_name']);
        } catch (EmailTakenException) {
            RateLimiter::clear($clientIp);

            return $this->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
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

    public function login(Request $request, array $vars = []): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['email', 'password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "email" and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->login((string) $body['email'], (string) $body['password'], (bool) ($body['remember_me'] ?? false));
            RateLimiter::clear($clientIp);
        } catch (InvalidCredentialsException) {
            return $this->error('INVALID_CREDENTIALS', 'The email address or password is incorrect.', Response::HTTP_UNAUTHORIZED);
        } catch (AccountUnverifiedException) {
            return $this->error('ACCOUNT_UNVERIFIED', 'Please verify your email address before logging in.', Response::HTTP_FORBIDDEN);
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

    public function logout(Request $request, array $vars = []): JsonResponse
    {
        $this->csrfService->invalidate();
        $this->authService->logout();

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    public function me(Request $request, array $vars = []): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($userId === null) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->userService->getUser($userId);

        if ($result === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
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
            ], 'csrf_token' => $this->csrfService->getToken()]],
            Response::HTTP_OK,
        );
    }

    public function password(Request $request, array $vars = []): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['current_password', 'new_password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "current_password" and "new_password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->changePassword((string) $body['current_password'], (string) $body['new_password']);
        } catch (\Delight\Auth\NotLoggedInException) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        } catch (\Delight\Auth\InvalidPasswordException) {
            return $this->error('INVALID_PASSWORD', 'The new password does not meet the minimum requirements.', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Delight\Auth\AuthError) {
            return $this->error('WRONG_PASSWORD', 'The current password is incorrect.', Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['message' => 'Password updated'], Response::HTTP_OK);
    }

    public function account(Request $request, array $vars = []): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->userService->updateUser($userId, $body);
        if ($result === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(
            ['data' => $result],
            Response::HTTP_OK,
        );
    }

    public function verify(Request $request, string $selector, array $vars = []): JsonResponse
    {
        $token = $request->query->get('token', '');

        if ($selector === '' || $token === '') {
            return $this->error('VALIDATION_ERROR', 'The selector and token are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->confirmEmail($selector, $token);
        } catch (\Delight\Auth\InvalidSelectorTokenPairException) {
            return $this->error('INVALID_TOKEN', 'The confirmation link is invalid.', Response::HTTP_BAD_REQUEST);
        } catch (\Delight\Auth\TokenExpiredException) {
            return $this->error('TOKEN_EXPIRED', 'The confirmation link has expired.', Response::HTTP_BAD_REQUEST);
        } catch (\Delight\Auth\UserAlreadyExistsException) {
            return $this->error('EMAIL_TAKEN', 'That email address is already in use.', Response::HTTP_CONFLICT);
        } catch (\Delight\Auth\TooManyRequestsException) {
            return $this->error('TOO_MANY_REQUESTS', 'Too many requests.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        return new JsonResponse(['message' => 'Email verified successfully.'], Response::HTTP_OK);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['email'])) {
            return $this->error('VALIDATION_ERROR', 'The field "email" is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->authService->forgotPassword((string) $body['email']);

        // Consider hitting the rate limiter even on success to prevent brute forcing
        RateLimiter::attempt($clientIp, self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_WINDOW_SECONDS);

        return new JsonResponse(['message' => 'If an account with that email exists, a password reset email has been sent.'], Response::HTTP_OK);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['selector', 'token', 'password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "selector", "token", and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->resetPassword((string) $body['selector'], (string) $body['token'], (string) $body['password']);
        } catch (\Delight\Auth\InvalidSelectorTokenPairException) {
            return $this->error('INVALID_TOKEN', 'The selector or token is invalid.', Response::HTTP_BAD_REQUEST);
        } catch (\Delight\Auth\TokenExpiredException) {
            return $this->error('INVALID_TOKEN', 'The token is invalid or has expired.', Response::HTTP_BAD_REQUEST);
        } catch (\Delight\Auth\ResetDisabledException) {
            return $this->error('RESET_DISABLED', 'Password reset is disabled.', Response::HTTP_FORBIDDEN);
        } catch (\Delight\Auth\InvalidPasswordException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Delight\Auth\AuthError) {
            return $this->error('AUTH_ERROR', 'An authentication error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['message' => 'Password reset successfully.'], Response::HTTP_OK);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $clientIp = $this->getClientIp($request);

        if ($this->isRateLimited($clientIp)) {
            return $this->rateLimitedResponse($clientIp);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['email'])) {
            return $this->error('VALIDATION_ERROR', 'The field "email" is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
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

    public function requestEmailChange(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['email'])) {
            return $this->error('VALIDATION_ERROR', 'The field "email" is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->changeEmail((string) $body['email']);
        } catch (\Delight\Auth\InvalidEmailException) {
            return $this->error('VALIDATION_ERROR', 'The provided email address is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Delight\Auth\UserAlreadyExistsException) {
            return $this->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (\Delight\Auth\EmailNotVerifiedException) {
            return $this->error('EMAIL_NOT_VERIFIED', 'You must verify your current email address before changing it.', Response::HTTP_FORBIDDEN);
        } catch (\Delight\Auth\NotLoggedInException) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        } catch (\Delight\Auth\AuthError) {
            return $this->error('AUTH_ERROR', 'An authentication error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['message' => 'A confirmation email has been sent to your new email address.'], Response::HTTP_OK);
    }

    public function confirmEmailChange(Request $request): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['selector', 'token'])) {
            return $this->error('VALIDATION_ERROR', 'The selector and token are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->confirmEmail((string) $body['selector'], (string) $body['token']);
        } catch (\Delight\Auth\InvalidSelectorTokenPairException) {
            return $this->error('INVALID_TOKEN', 'The confirmation link is invalid.', Response::HTTP_BAD_REQUEST);
        } catch (\Delight\Auth\TokenExpiredException) {
            return $this->error('TOKEN_EXPIRED', 'The confirmation link has expired.', Response::HTTP_BAD_REQUEST);
        } catch (\Delight\Auth\UserAlreadyExistsException) {
            return $this->error('EMAIL_TAKEN', 'That email address is already in use.', Response::HTTP_CONFLICT);
        } catch (\Delight\Auth\TooManyRequestsException) {
            return $this->error('TOO_MANY_REQUESTS', 'Too many requests.', Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\Delight\Auth\AuthError) {
            return $this->error('AUTH_ERROR', 'An error occurred confirming email change.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['message' => 'Email address changed successfully.'], Response::HTTP_OK);
    }

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

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function missingFields(array $body, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($body[$field] ?? '') === '') {
                return true;
            }
        }

        return false;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
