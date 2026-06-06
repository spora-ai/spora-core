<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTime;
use Delight\Auth\AuthException;
use InvalidArgumentException;
use Spora\Auth\AuthService;
use Spora\Auth\Exceptions\AccountUnverifiedException;
use Spora\Auth\Exceptions\EmailTakenException;
use Spora\Auth\Exceptions\InvalidCredentialsException;
use Spora\Security\CsrfTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workflow helpers extracted from {@see \Spora\Http\AuthController}.
 *
 * Each public method owns one slice of the auth workflow (decode → validate → perform),
 * including the try/catch arm that maps delight-im exceptions to JSON responses. The
 * controller delegates to these methods so it can stay under the S1448 (≤20 methods)
 * and S1142 (≤3 returns) limits.
 *
 * Mirrors the {@see LlmConfigValidator} pattern: a thin service the controller depends
 * on, returning {@see JsonResponse} for both success and error paths because every
 * caller is a controller. Returning a generic result type would just push the
 * mapping code back into the controller.
 */
final class AuthWorkflow
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    private const MSG_EMAIL_REQUIRED = 'The field "email" is required.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserServiceInterface $userService,
        private readonly CsrfTokenService $csrfService,
        private readonly AuthValidator $validator,
    ) {}

    /**
     * Decode the body, then validate the registration payload. Delegates the
     * delight-im call to {@see performRegister()} so the try/catch lives in
     * exactly one place.
     */
    public function handleRegister(Request $request, string $clientIp): JsonResponse
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
    public function validateRegisterFields(array $body): ?JsonResponse
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
    public function performRegister(array $body, string $clientIp): JsonResponse
    {
        try {
            $userId = $this->authService->register((string) $body['email'], (string) $body['password'], (string) $body['display_name']);
        } catch (EmailTakenException) {
            RateLimiter::clear($clientIp);

            return $this->validator->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT);
        } catch (InvalidArgumentException $e) {
            return $this->validator->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['data' => [
                'user' => ['id' => $userId, 'email' => $body['email']],
                'csrf_token' => $this->csrfService->regenerate(),
            ]],
            Response::HTTP_CREATED,
        );
    }

    public function handleLogin(Request $request, string $clientIp): JsonResponse
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
    public function performLogin(array $body, string $clientIp): JsonResponse
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

        return new JsonResponse(
            ['data' => [
                'user' => $userData,
                'csrf_token' => $this->csrfService->regenerate(),
            ]],
            Response::HTTP_OK,
        );
    }

    public function buildMeResponse(int $userId): JsonResponse
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
    public function performPasswordChange(array $body): JsonResponse
    {
        try {
            $this->authService->changePassword((string) $body['current_password'], (string) $body['new_password']);
        } catch (AuthException $e) {
            return $this->validator->mapPasswordChangeError($e);
        }

        return new JsonResponse(['message' => 'Password updated'], Response::HTTP_OK);
    }

    public function updateAccountAndRespond(int $userId, Request $request): JsonResponse
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

    public function performEmailVerification(string $selector, string $token): JsonResponse
    {
        try {
            $this->authService->confirmEmail($selector, $token);
        } catch (AuthException $e) {
            return $this->validator->mapEmailVerificationError($e);
        }

        return new JsonResponse(['message' => 'Email verified successfully.'], Response::HTTP_OK);
    }

    public function handleForgotPassword(Request $request, string $clientIp): JsonResponse
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
    public function performPasswordReset(array $body): JsonResponse
    {
        try {
            $this->authService->resetPassword((string) $body['selector'], (string) $body['token'], (string) $body['password']);
        } catch (AuthException $e) {
            return $this->validator->mapPasswordResetError($e);
        }

        return new JsonResponse(['message' => 'Password reset successfully.'], Response::HTTP_OK);
    }

    public function handleResendVerification(Request $request, string $clientIp): JsonResponse
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

    public function handleEmailChangeRequest(Request $request): JsonResponse
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
    public function performEmailChangeRequest(array $body): JsonResponse
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
    public function performEmailChangeConfirmation(array $body): JsonResponse
    {
        try {
            $this->authService->confirmEmail((string) $body['selector'], (string) $body['token']);
        } catch (AuthException $e) {
            return $this->validator->mapEmailChangeConfirmationError($e);
        }

        return new JsonResponse(['message' => 'Email address changed successfully.'], Response::HTTP_OK);
    }
}
