<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\I18n\LocaleResolver;
use Spora\Models\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads and writes the caller's own `users.locale`.
 *
 * Deliberately user-scoped, unlike `/user-preferences/llm`: the language
 * belongs to the person, not to the principal they are currently acting as,
 * so this endpoint takes no `?principal_id=` and a group principal cannot
 * override it. See migration 0096 for why the column is on `users`.
 *
 * `null` is a real value — it means "auto", and clears the choice so
 * `SPORA_DEFAULT_LANG` applies again. The available set is whatever
 * `locales/` holds, so adding a language is a file, not a release.
 */
final class UserLocaleController
{
    private const ERR_INVALID_JSON_MESSAGE = 'Request body must be valid JSON.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly LocaleResolver $localeResolver,
    ) {}

    /**
     * GET /api/v1/user-preferences/locale
     *
     * Returns the caller's stored locale (`null` = auto) and the locale it
     * resolves to right now, so the SPA does not have to reimplement the
     * chain to show the current selection.
     */
    public function show(): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $stored = User::where('id', $userId)->value('locale');

        return new JsonResponse(['data' => [
            'locale'      => is_string($stored) ? $stored : null,
            'resolved'    => $this->localeResolver->resolve(is_string($stored) ? $stored : null),
            'available'   => $this->localeResolver->availableLocales(),
        ]]);
    }

    /**
     * PUT /api/v1/user-preferences/locale
     *
     * Body: { "locale": "de" } or { "locale": null } to go back to auto.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $locale = $this->requestedLocale($request);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }

        $user->locale = $locale;
        $user->save();

        return new JsonResponse(['data' => [
            'locale'   => $locale,
            'resolved' => $this->localeResolver->resolve($locale),
        ]]);
    }

    /**
     * The caller, or the error to return instead.
     *
     * Both failures are identity failures: a caller with no session and a
     * caller whose row is gone are both "no such user" to this endpoint.
     */
    private function currentUser(): User|JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $user = User::find($userId);
        if ($user === null) {
            return $this->error('NOT_FOUND', 'User not found.', Response::HTTP_NOT_FOUND);
        }

        return $user;
    }

    /**
     * The locale the request asks for, or the error to return instead.
     *
     * `null` is a legitimate answer — it means "auto" — so the error is
     * signalled by its type, not by a null.
     */
    private function requestedLocale(Request $request): JsonResponse|string|null
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = null;
        }

        // A body that is not JSON at all, or one that decodes to something
        // else — the bare string `"de"` — is not a request, and reading a
        // locale out of it would store "auto" and answer 200 for a request
        // that never carried one.
        if (!is_array($body)) {
            return $this->error('INVALID_JSON', self::ERR_INVALID_JSON_MESSAGE, Response::HTTP_BAD_REQUEST);
        }

        $locale = $body['locale'] ?? null;

        if ($locale !== null && !in_array($locale, $this->localeResolver->availableLocales(), true)) {
            return $this->error(
                'VALIDATION_ERROR',
                sprintf('Unknown locale "%s".', is_scalar($locale) ? (string) $locale : ''),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return is_string($locale) ? $locale : null;
    }

    private function unauthenticated(): JsonResponse
    {
        return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
