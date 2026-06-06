<?php

declare(strict_types=1);

namespace Spora\Services;

use Delight\Auth\AuthException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validation, decoding, and catch-arm error mappers for {@see \Spora\Http\AuthController}.
 *
 * Mirrors the {@see LlmConfigValidator} pattern: a thin framework-aware service that owns
 * the JSON decoding, field validation, and exception → JsonResponse mapping so the
 * controller can stay under the S1448 (≤20 methods) and S1142 (≤3 returns) limits.
 *
 * The class is intentionally framework-aware (it returns {@see JsonResponse} for error
 * paths) because every caller is a controller. Returning a generic result type would
 * just push the mapping code back into the controller.
 */
final class AuthValidator
{
    public const MSG_INVALID_JSON = 'Request body must be valid JSON.';
    public const MSG_AUTHENTICATION_REQUIRED = 'Authentication required.';

    /**
     * Decode the JSON body, returning a 400 JsonResponse on failure.
     * Lets callers write `if ($body instanceof JsonResponse) return $body;`
     * instead of nesting a try/catch in every public method.
     *
     * @return array<string, mixed>|JsonResponse
     */
    public function decodeBodyOrFail(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }
    }

    public function unauthenticated(): JsonResponse
    {
        return $this->error('UNAUTHENTICATED', self::MSG_AUTHENTICATION_REQUIRED, Response::HTTP_UNAUTHORIZED);
    }

    public function invalidJson(): JsonResponse
    {
        return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
    }

    public function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $fields
     */
    public function missingFields(array $body, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($body[$field] ?? '') === '') {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Catch-arm mappers — each mirrors the match (true) { $e instanceof X => ... }
    // pattern from the AuthController. Order matters: most specific first.
    // ---------------------------------------------------------------------

    public function mapEmailVerificationError(AuthException $e): JsonResponse
    {
        return match (true) {
            $e instanceof \Delight\Auth\InvalidSelectorTokenPairException => $this->error('INVALID_TOKEN', 'The confirmation link is invalid.', Response::HTTP_BAD_REQUEST),
            $e instanceof \Delight\Auth\TokenExpiredException => $this->error('TOKEN_EXPIRED', 'The confirmation link has expired.', Response::HTTP_BAD_REQUEST),
            $e instanceof \Delight\Auth\UserAlreadyExistsException => $this->error('EMAIL_TAKEN', 'That email address is already in use.', Response::HTTP_CONFLICT),
            $e instanceof \Delight\Auth\TooManyRequestsException => $this->error('TOO_MANY_REQUESTS', 'Too many requests.', Response::HTTP_TOO_MANY_REQUESTS),
            default => throw $e,
        };
    }

    public function mapPasswordResetError(AuthException $e): JsonResponse
    {
        return match (true) {
            $e instanceof \Delight\Auth\InvalidSelectorTokenPairException => $this->error('INVALID_TOKEN', 'The selector or token is invalid.', Response::HTTP_BAD_REQUEST),
            $e instanceof \Delight\Auth\TokenExpiredException => $this->error('INVALID_TOKEN', 'The token is invalid or has expired.', Response::HTTP_BAD_REQUEST),
            $e instanceof \Delight\Auth\ResetDisabledException => $this->error('RESET_DISABLED', 'Password reset is disabled.', Response::HTTP_FORBIDDEN),
            $e instanceof \Delight\Auth\InvalidPasswordException => $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY),
            default => $this->error('AUTH_ERROR', 'An authentication error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    public function mapEmailChangeRequestError(AuthException $e): JsonResponse
    {
        return match (true) {
            $e instanceof \Delight\Auth\InvalidEmailException => $this->error('VALIDATION_ERROR', 'The provided email address is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY),
            $e instanceof \Delight\Auth\UserAlreadyExistsException => $this->error('EMAIL_TAKEN', 'A user with that email address already exists.', Response::HTTP_CONFLICT),
            $e instanceof \Delight\Auth\EmailNotVerifiedException => $this->error('EMAIL_NOT_VERIFIED', 'You must verify your current email address before changing it.', Response::HTTP_FORBIDDEN),
            $e instanceof \Delight\Auth\NotLoggedInException => $this->error('UNAUTHENTICATED', self::MSG_AUTHENTICATION_REQUIRED, Response::HTTP_UNAUTHORIZED),
            default => $this->error('AUTH_ERROR', 'An authentication error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    public function mapEmailChangeConfirmationError(AuthException $e): JsonResponse
    {
        return match (true) {
            $e instanceof \Delight\Auth\InvalidSelectorTokenPairException => $this->error('INVALID_TOKEN', 'The confirmation link is invalid.', Response::HTTP_BAD_REQUEST),
            $e instanceof \Delight\Auth\TokenExpiredException => $this->error('TOKEN_EXPIRED', 'The confirmation link has expired.', Response::HTTP_BAD_REQUEST),
            $e instanceof \Delight\Auth\UserAlreadyExistsException => $this->error('EMAIL_TAKEN', 'That email address is already in use.', Response::HTTP_CONFLICT),
            $e instanceof \Delight\Auth\TooManyRequestsException => $this->error('TOO_MANY_REQUESTS', 'Too many requests.', Response::HTTP_TOO_MANY_REQUESTS),
            default => $this->error('AUTH_ERROR', 'An error occurred confirming email change.', Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    public function mapPasswordChangeError(AuthException $e): JsonResponse
    {
        return match (true) {
            $e instanceof \Delight\Auth\NotLoggedInException => $this->error('UNAUTHENTICATED', self::MSG_AUTHENTICATION_REQUIRED, Response::HTTP_UNAUTHORIZED),
            $e instanceof \Delight\Auth\InvalidPasswordException => $this->error('INVALID_PASSWORD', 'The new password does not meet the minimum requirements.', Response::HTTP_UNPROCESSABLE_ENTITY),
            default => throw $e,
        };
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
