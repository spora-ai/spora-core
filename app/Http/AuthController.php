<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTime;
use InvalidArgumentException;
use Spora\Auth\AuthService;
use Spora\Auth\Exceptions\AccountUnverifiedException;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Auth\Exceptions\InvalidCredentialsException;
use Spora\Models\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly array $config = [],
    ) {}

    public function register(Request $request, array $vars = []): JsonResponse
    {
        if (!($this->config['allow_registration'] ?? true)) {
            return $this->error('REGISTRATION_DISABLED', 'Registration is currently disabled.', Response::HTTP_FORBIDDEN);
        }

        $body = $this->decodeJson($request);

        if ($this->missingFields($body, ['email', 'password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "email" and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $userId = $this->authService->register((string) $body['email'], (string) $body['password']);
        } catch (EmailTakenException) {
            return $this->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['data' => ['user' => ['id' => $userId, 'email' => $body['email']]]],
            Response::HTTP_CREATED,
        );
    }

    public function login(Request $request, array $vars = []): JsonResponse
    {
        $body = $this->decodeJson($request);

        if ($this->missingFields($body, ['email', 'password'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "email" and "password" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->authService->login((string) $body['email'], (string) $body['password'], (bool) ($body['remember_me'] ?? false));
        } catch (InvalidCredentialsException) {
            return $this->error('INVALID_CREDENTIALS', 'The email address or password is incorrect.', Response::HTTP_UNAUTHORIZED);
        } catch (AccountUnverifiedException) {
            return $this->error('ACCOUNT_UNVERIFIED', 'Please verify your email address before logging in.', Response::HTTP_FORBIDDEN);
        }

        $userId = $this->authService->currentUserId();
        $user   = User::find($userId);

        return new JsonResponse(
            ['data' => ['user' => [
                'id'       => $userId,
                'email'    => $user !== null ? $user->email : (string) $body['email'],
                'username' => $user !== null ? $user->username : null,
            ]]],
            Response::HTTP_OK,
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

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        $decoded = $content !== '' ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : [];
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
