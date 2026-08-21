<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Services\GroupDetailResource;
use Spora\Services\GroupService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\PrincipalService;
use Spora\Services\ProfilePictures\GroupPictureService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Group picture upload + delete endpoints.
 *
 * - POST   /api/v1/groups/{id}/picture/image  — multipart upload, 1 MiB cap,
 *                                              image MIMEs only (PNG/JPEG/WebP).
 * - DELETE /api/v1/groups/{id}/picture/image  — clear the uploaded image,
 *                                              fall back to the default
 *                                              archetype avatar.
 *
 * Mirrors {@see AgentPictureController} — the shared multipart-upload
 * pipeline lives in {@see AvatarUploadSupport}. The two controllers only
 * diverge on (a) which row holds the picture, (b) which authorisation
 * gate applies (admin OR owner/admin of the group instead of the agent
 * ownership check), and (c) which resource carries the picture in the
 * response.
 */
final class GroupPictureController
{
    use AvatarUploadSupport;
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $authService,
        private readonly PrincipalService $principalService,
        private readonly GroupPictureService $pictureService,
        private readonly MediaArchiveService $mediaArchive,
        private readonly MimeSniffer $sniffer,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
        private readonly array $config = [],
    ) {}

    /**
     * POST /api/v1/groups/{id}/picture/image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }
        $groupId = (int) $request->attributes->get('id', 0);

        $groupError = $this->resolveGroupForUpload($groupId, $userId);
        if ($groupError !== null) {
            return $groupError;
        }

        $prepared = $this->prepareUpload($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        return $this->performUpload($prepared['file'], $prepared['bytes'], $groupId, $userId);
    }

    /**
     * Look up the group for an upload. Authorisation: admin OR owner/admin
     * of the group. Returns null on success, or a 4xx JsonResponse on
     * failure. Extracted from {@see uploadImage()} so the controller
     * stays under the 3-return ceiling (S1142).
     */
    private function resolveGroupForUpload(int $groupId, int $userId): ?JsonResponse
    {
        $group = Group::find($groupId);
        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
        }
        if ($this->authService->isAdmin()) {
            return null;
        }
        $role = GroupService::fetchCallerRole($groupId, $userId);
        if ($role !== GroupMembership::ROLE_OWNER && $role !== GroupMembership::ROLE_ADMIN) {
            return $this->forbidden('FORBIDDEN', 'Only group owners or admins can manage the group picture.');
        }
        return null;
    }

    /**
     * Run the byte-path (MediaArchive → group_pictures write) after the
     * controller has finished the input validations. Extracted from
     * `uploadImage()` so the controller stays under the 3-return ceiling.
     */
    private function performUpload(
        UploadedFile $file,
        string $bytes,
        int $groupId,
        int $userId,
    ): JsonResponse {
        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $this->sniffer->sniffFromBytes($bytes),
            filename: $this->sanitisedClientName($file),
            userId: $userId,
            uploadSource: 'avatar',
        ));

        $this->pictureService->attachImage($groupId, $asset, $userId);
        $group = Group::find($groupId);
        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
        }

        return new JsonResponse([
            'data' => [
                'asset'  => $this->serializer->serialize($asset, (string) ($this->config['app_url'] ?? '')),
                'group'  => GroupDetailResource::toArray($group, $userId, $this->principalService, $this->pictureService),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/groups/{id}/picture/image
     */
    public function deleteImage(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }
        $groupId = (int) $request->attributes->get('id', 0);

        $groupError = $this->resolveGroupForUpload($groupId, $userId);
        if ($groupError !== null) {
            return $groupError;
        }

        $this->pictureService->detachImage($groupId);

        $group = Group::find($groupId);
        return new JsonResponse([
            'data' => [
                'group' => $group !== null ? GroupDetailResource::toArray($group, $userId, $this->principalService, $this->pictureService) : null,
            ],
        ]);
    }
}
