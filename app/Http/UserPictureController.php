<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\UserPictures\UserPictureService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * User profile picture write surface — the authenticated user manages
 * their own picture only.
 *
 * - GET    /api/v1/me/picture          — fetch the caller's wire shape
 *                                        (`null` when no upload yet).
 * - POST   /api/v1/me/picture/image    — multipart upload, 1 MiB cap,
 *                                        image MIMEs only (PNG/JPEG/WebP).
 * - DELETE /api/v1/me/picture/image    — drop the upload and revert to
 *                                        the initials fallback.
 *
 * `/me` is the authorisation — there is no per-id lookup because the
 * only legitimate writer is the user themselves, mirroring how
 * {@see UserProfileController} exposes `/api/v1/me/profile`.
 *
 * The multipart-upload pipeline (size cap, MIME allowlist, byte-decode
 * verification) is shared with {@see AgentPictureController} and
 * {@see GroupPictureController} via {@see AvatarUploadSupport}. The
 * trait assumes the using class has a `private readonly MimeSniffer $sniffer`
 * property — wired through the constructor below.
 */
final class UserPictureController
{
    use AvatarUploadSupport;
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserPictureService $pictures,
        private readonly MimeSniffer $sniffer,
    ) {}

    /**
     * GET /api/v1/me/picture
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->requireUser();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        return new JsonResponse([
            'data' => [
                'profile_picture' => $this->pictures->toWireShape($this->pictures->findForUser($userId)),
            ],
        ]);
    }

    /**
     * POST /api/v1/me/picture/image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $userId = $this->requireUser();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $prepared = $this->prepareUpload($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        return $this->performUpload($prepared['file'], $prepared['bytes'], $userId);
    }

    /**
     * DELETE /api/v1/me/picture/image
     */
    public function deleteImage(Request $request): JsonResponse
    {
        $userId = $this->requireUser();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $this->pictures->delete($userId);

        return new JsonResponse([
            'data' => [
                'profile_picture' => null,
            ],
        ]);
    }

    /**
     * Extract the caller user id, returning a 401 JsonResponse when
     * no session is present. The route's middleware stack should
     * already reject unauthenticated requests, but the explicit
     * check keeps the controller safe when it is invoked directly
     * from a test or a future code path that bypasses the
     * middleware.
     */
    private function requireUser(): int|JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }
        return $userId;
    }

    /**
     * Write the validated bytes to disk + the user_pictures row and
     * return the resulting wire shape. Extracted from
     * {@see uploadImage()} so the controller stays under the
     * SonarQube S1142 3-return ceiling.
     */
    private function performUpload(UploadedFile $file, string $bytes, int $userId): JsonResponse
    {
        $mime = $this->sniffer->sniffFromBytes($bytes);
        $picture = $this->pictures->upload(
            $userId,
            $mime,
            strlen($bytes),
            $bytes,
        );

        return new JsonResponse([
            'data' => [
                'profile_picture' => $this->pictures->toWireShape($picture),
            ],
        ], Response::HTTP_CREATED);
    }
}
