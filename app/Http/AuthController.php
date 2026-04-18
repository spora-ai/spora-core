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
use Spora\Models\User;
use Spora\Services\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly AuthService $authService,
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

        if ($this->missingFields($body, ['email', 'password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "email" and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $userId = $this->authService->register((string) $body['email'], (string) $body['password']);
            RateLimiter::clear($clientIp);
        } catch (EmailTakenException) {
            return $this->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->withRateLimitHeaders(
            new JsonResponse(
                ['data' => ['user' => ['id' => $userId, 'email' => $body['email']]]],
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
        $user   = User::find($userId);

        return $this->withRateLimitHeaders(
            new JsonResponse(
                ['data' => ['user' => [
                    'id'       => $userId,
                    'email'    => $user !== null ? $user->email : (string) $body['email'],
                    'username' => $user !== null ? $user->username : null,
                ]]],
                Response::HTTP_OK,
            ),
            $clientIp,
        );
    }

    public function logout(Request $request, array $vars = []): JsonResponse
    {
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

        $user = User::find($userId);

        if ($user === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        $registered = (new DateTime())->setTimestamp((int) $user->registered)->format(DateTime::ATOM);

        return new JsonResponse(
            ['data' => ['user' => [
                'id'         => (int) $user->id,
                'email'      => $user->email,
                'username'   => $user->username,
                'registered' => $registered,
            ]]],
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

        $user = User::find($userId);
        if ($user === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        if (isset($body['username'])) {
            $user->username = (string) $body['username'];
        }
        $user->save();

        return new JsonResponse(
            ['data' => ['user' => [
                'id'       => (int) $user->id,
                'email'    => $user->email,
                'username' => $user->username,
            ]]],
            Response::HTTP_OK,
        );
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
