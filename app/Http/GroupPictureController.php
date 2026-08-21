<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\AvatarFileReadFailedException;
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
use Spora\Services\Text\Utf8Sanitizer;
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
 * Mirrors {@see AgentPictureController} — reuses the same MediaArchive
 * ingestion path, the same 1 MiB cap, the same MIME allowlist, and the
 * same image-decoding defence-in-depth. The two controllers only
 * diverge on (a) which row holds the picture, (b) which authorisation
 * gate applies (admin OR owner/admin of the group instead of the agent
 * ownership check), and (c) which resource carries the picture in the
 * response.
 */
final class GroupPictureController
{
    use JsonControllerHelpers;

    /**
     * 1 MiB. Same cap as {@see AgentPictureController}; see that class
     * for the rationale (visual-quality needs ≤ 256x256 PNG under 64 KB,
     * 1 MiB keeps dashboard renders fast).
     */
    private const MAX_AVATAR_BYTES = 1 * 1024 * 1024;

    /**
     * Avatar MIME allowlist. Same as {@see AgentPictureController} —
     * avatars are always raster images, sniffed (Content-Type ignored)
     * then decoded to confirm PHP can actually render them.
     *
     * @var list<string>
     */
    private const ALLOWED_AVATAR_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    public function __construct(
        private readonly AuthService $authService,
        private readonly GroupPictureService $pictureService,
        private readonly PrincipalService $principalService,
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
        $groupId = (int) $request->attributes->get('id', 0);

        $auth = $this->requireCallerAndWriteAccess($groupId);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        $callerUserId = $auth[0];

        $prepared = $this->prepareUpload($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        return $this->performUpload($prepared['file'], $prepared['bytes'], $groupId, $callerUserId);
    }

    /**
     * Validate the uploaded file (size + declared mime from the multipart
     * envelope), then read its bytes for MIME sniffing and image-decoding
     * verification. Returns the parsed file + bytes on success or a 4xx
     * JsonResponse on any failure. The cheap, non-byte checks run *before*
     * `file_get_contents()` so the 1 MiB cap actually bounds upload-path
     * memory consumption. Group authorisation is checked in the public
     * endpoint so this helper stays under the 3-return ceiling (S1142).
     *
     * @return array{file: UploadedFile, bytes: string}|JsonResponse
     */
    private function prepareUpload(Request $request): array|JsonResponse
    {
        $file = $this->extractUploadedFile($request);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        $bytes = $this->readAndValidateUploadBytes($file);
        if ($bytes instanceof JsonResponse) {
            return $bytes;
        }

        return ['file' => $file, 'bytes' => $bytes];
    }

    /**
     * Pull the `file` field off the multipart envelope, ensure it is a real
     * {@see UploadedFile}, and run the cheap, envelope-level size check
     * *before* {@see readFileBytes()} so the 1 MiB cap actually bounds
     * upload-path memory consumption.
     *
     * @return UploadedFile|JsonResponse
     */
    private function extractUploadedFile(Request $request): UploadedFile|JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->error('BAD_REQUEST', 'No file uploaded under the "file" field.', Response::HTTP_BAD_REQUEST);
        }

        $sizeError = $this->validateUploadSize($file);
        if ($sizeError !== null) {
            return $sizeError;
        }

        return $file;
    }

    /**
     * Read the upload's bytes and run the byte-path validations (real
     * length cap + sniffed MIME + decodable image). Returns the raw bytes
     * on success or a 4xx JsonResponse on the first failure.
     */
    private function readAndValidateUploadBytes(UploadedFile $file): string|JsonResponse
    {
        $bytes = $this->readFileBytes($file);
        if (strlen($bytes) > self::MAX_AVATAR_BYTES) {
            return $this->error(
                'PAYLOAD_TOO_LARGE',
                sprintf('Avatar image exceeds %d bytes.', self::MAX_AVATAR_BYTES),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $mimeError = $this->validateUploadMime($bytes);
        if ($mimeError !== null) {
            return $mimeError;
        }

        return $bytes;
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
        int $callerUserId,
    ): JsonResponse {
        $clientName = $file->getClientOriginalName();
        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $this->sniffer->sniffFromBytes($bytes),
            filename: $clientName !== ''
                ? Utf8Sanitizer::scrubString($clientName)
                : null,
            userId: $callerUserId,
            agentId: null,
            uploadSource: 'group_avatar',
        ));

        $this->pictureService->attachImage($groupId, $asset, $callerUserId);

        $group = Group::find($groupId);
        return new JsonResponse([
            'data' => [
                'asset' => $this->serializer->serialize($asset, (string) ($this->config['app_url'] ?? '')),
                'group' => $group !== null
                    ? GroupDetailResource::toArray($group, $callerUserId, $this->principalService, $this->pictureService)
                    : null,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Validate the upload's integrity + declared size (from the multipart
     * envelope). Returns 4xx JsonResponse on failure, null on success.
     */
    private function validateUploadSize(UploadedFile $file): ?JsonResponse
    {
        if (!$file->isValid()) {
            return $this->error('BAD_REQUEST', 'Upload failed: ' . $file->getErrorMessage(), Response::HTTP_BAD_REQUEST);
        }
        if ($file->getSize() > self::MAX_AVATAR_BYTES) {
            return $this->error(
                'PAYLOAD_TOO_LARGE',
                sprintf('Avatar image exceeds %d bytes.', self::MAX_AVATAR_BYTES),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }
        return null;
    }

    /**
     * Validate the upload's actual content: sniff the bytes (ignoring the
     * client-supplied Content-Type header), then ask PHP to decode the
     * bytes as an image. Returns 4xx JsonResponse on failure, null on
     * success.
     */
    private function validateUploadMime(string $bytes): ?JsonResponse
    {
        $sniffedMime = strtolower($this->sniffer->sniffFromBytes($bytes));
        if (!in_array($sniffedMime, self::ALLOWED_AVATAR_MIMES, true)) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                sprintf('MIME type "%s" is not a supported avatar image type.', $sniffedMime),
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                'Avatar image bytes could not be decoded.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }
        return null;
    }

    /**
     * Read the uploaded file's bytes; throw on read failure.
     */
    private function readFileBytes(UploadedFile $file): string
    {
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) {
            throw AvatarFileReadFailedException::onPath($file->getPathname());
        }
        return $bytes;
    }

    /**
     * DELETE /api/v1/groups/{id}/picture/image
     */
    public function deleteImage(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $groupId = (int) $request->attributes->get('id', 0);

        $auth = $this->requireCallerAndWriteAccess($groupId);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        $callerUserId = $auth[0];

        $this->pictureService->detachImage($groupId);

        $group = Group::find($groupId);
        return new JsonResponse([
            'data' => [
                'group' => $group !== null
                    ? GroupDetailResource::toArray($group, $callerUserId, $this->principalService, $this->pictureService)
                    : null,
            ],
        ]);
    }

    /**
     * Common preamble for upload/delete: requires caller is logged in
     * AND is admin OR owner of the group. Returns [callerUserId] on
     * success, or a JsonResponse to short-circuit.
     *
     * @return array{0: int}|JsonResponse
     */
    private function requireCallerAndWriteAccess(int $groupId): array|JsonResponse
    {
        $callerUserId = $this->authService->currentUserId();
        if ($callerUserId === null) {
            return $this->unauthenticated();
        }

        $group = Group::find($groupId);
        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
        }

        if ($this->callerMayManagePicture($groupId, $callerUserId)) {
            return [$callerUserId];
        }

        return $this->forbidden('FORBIDDEN', 'Only group owners or admins can change the group picture.');
    }

    private function callerMayManagePicture(int $groupId, int $callerUserId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        return GroupService::fetchCallerRole($groupId, $callerUserId) === GroupMembership::ROLE_OWNER;
    }
}
