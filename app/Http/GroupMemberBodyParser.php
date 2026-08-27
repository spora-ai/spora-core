<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Models\GroupMembership;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Body-parsing helpers for {@see GroupMemberController} extracted so
 * the controller stays under the SonarCloud S1448 20-method cap.
 *
 * Each helper is a pure-function shape (no `$this`): they accept the
 * body to parse plus the {@see UserServiceInterface} collaborator +
 * a callable for the 422 / 404 envelopes. Extracted as static methods
 * on a final class rather than instance methods on the controller so
 * the controller's method count stays bounded as new helpers appear.
 *
 * The controller passes `$this->unprocessable(...)` / `$this->notFound(...)`
 * as the envelope callbacks to keep the wire-format literals in one
 * place — `JsonControllerHelpers::unprocessable()` / `notFound()` are
 * private trait methods on the controller, so we can't call them from
 * a non-trait class directly.
 */
final class GroupMemberBodyParser
{
    /**
     * Resolve the target user id from the wire body's `user_id` or
     * `email` slot. Exactly one is required — the wire contract accepts
     * either so the frontend can pick the friendlier input (email) while
     * machine-to-machine callers keep their integer-id path.
     *
     * @param  array<string, mixed>       $body
     * @param  UserServiceInterface       $userService
     * @param  callable(string, string): JsonResponse  $unprocessable 422 envelope factory
     * @param  callable(string, string): JsonResponse  $notFound      404 envelope factory
     * @return int|JsonResponse
     */
    public static function resolveTargetUserId(
        array $body,
        UserServiceInterface $userService,
        callable $unprocessable,
        callable $notFound,
    ): int|JsonResponse {
        $hasUserId = isset($body['user_id']) && (int) $body['user_id'] > 0;
        $hasEmail = isset($body['email']) && trim((string) $body['email']) !== '';

        if ($hasUserId === $hasEmail) {
            return $unprocessable(
                'VALIDATION_ERROR',
                'Provide exactly one of "user_id" (integer) or "email" (string).',
            );
        }
        if ($hasUserId) {
            return (int) $body['user_id'];
        }
        return self::resolveEmailTarget((string) $body['email'], $userService, $unprocessable, $notFound);
    }

    /**
     * @param  mixed                     $rawEmail
     * @param  UserServiceInterface      $userService
     * @param  callable(string, string): JsonResponse $unprocessable 422 envelope factory
     * @param  callable(string, string): JsonResponse $notFound      404 envelope factory
     * @return int|JsonResponse
     */
    public static function resolveEmailTarget(
        $rawEmail,
        UserServiceInterface $userService,
        callable $unprocessable,
        callable $notFound,
    ): int|JsonResponse {
        $email = strtolower(trim((string) $rawEmail));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $unprocessable('VALIDATION_ERROR', 'The "email" field must be a valid email address.');
        }
        $targetUserId = $userService->getUserIdByEmail($email);
        if ($targetUserId === null) {
            return $notFound('USER_NOT_FOUND', sprintf('No user exists with email "%s".', $email));
        }
        return $targetUserId;
    }

    /**
     * @param  callable(string, string): JsonResponse $unprocessable 422 envelope factory
     * @return string|JsonResponse
     */
    public static function validateRole(string $role, callable $unprocessable): string|JsonResponse
    {
        if (!in_array($role, [GroupMembership::ROLE_OWNER, GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MEMBER], true)) {
            return $unprocessable('VALIDATION_ERROR', "Unknown role: {$role}");
        }
        return $role;
    }
}
