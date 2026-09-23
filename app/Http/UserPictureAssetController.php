<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Models\UserPicture;
use Spora\Services\UserPictures\UserPictureService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * User profile picture read surface — `GET /api/v1/users/{id}/picture`.
 *
 * Serves the bytes of a user's uploaded profile picture to any
 * authenticated caller. Visibility is intentionally *not* principal-
 * scoped: the navbar identity card, the group-members list, and any
 * future "who is this person?" view all need the picture for users
 * the caller might not share a group with yet (a fresh signup whose
 * only principal is their own user-principal).
 *
 * Because the URL `{id}` carries no per-caller authorisation beyond
 * "logged in", we deliberately do NOT route through
 * {@see \Spora\Http\AssetController}: that controller applies the
 * `media_assets` principal-ownership union, which would 404 every
 * cross-user picture fetch. The trade-off — duplicating ~30 lines of
 * file-serving code instead of a one-line visibility tweak on
 * MediaArchive — is worth it because user pictures are a different
 * access class (global-within-auth) from MediaArchive (principal-
 * scoped).
 */
final class UserPictureAssetController
{
    /** 24h browser cache: bytes don't change unless the user re-uploads. */
    private const CACHE_HEADER = 'private, max-age=86400';

    public function __construct(
        private readonly AuthService $auth,
        private readonly UserPictureService $pictures,
    ) {}

    public function show(int $id): Response
    {
        if ($this->auth->currentUserId() === null) {
            return $this->unauthenticated();
        }

        $picture = UserPicture::where('user_id', $id)->first();
        $path = $picture instanceof UserPicture ? $this->pictures->absolutePathFor($picture) : null;
        if ($path === null) {
            return $this->notFound();
        }

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $picture->mime]);
        $response->headers->set('Cache-Control', self::CACHE_HEADER);
        return $response;
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'user_picture_not_found', 'message' => 'User picture not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
