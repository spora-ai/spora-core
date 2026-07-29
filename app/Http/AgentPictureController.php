<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\AvatarFileReadFailedException;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentResource;
use Spora\Services\AgentServiceInterface;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\Text\Utf8Sanitizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent picture upload + delete endpoints.
 *
 * - POST   /api/v1/agents/{id}/picture/image  — multipart upload, 1 MiB cap,
 *                                              image MIMEs only (PNG/JPEG/WebP).
 * - DELETE /api/v1/agents/{id}/picture/image  — clear the uploaded image,
 *                                              fall back to the default
 *                                              archetype avatar.
 *
 * Reuses {@see MediaArchiveService::ingest()} so the bytes land in the same
 * `media_assets` table the rest of the Media Archive uses; the controller
 * just stamps `upload_source='avatar'` (a third documented value sitting
 * alongside 'upload' and 'tool') so the agent_pictures row carries the
 * 1:1 FK to the asset.
 *
 * The `1 MiB` cap is enforced at this layer (NOT inside MediaArchiveService)
 * because the global `asset_store.max_bytes` is 50 MiB and avatar uploads
 * are a special case. We keep the tighter constraint at the picture layer
 * so the global asset cap can stay at 50 MiB for other consumers.
 */
final class AgentPictureController
{
    use JsonControllerHelpers;

    /**
     * 1 MiB. Bigger than the visual quality needs require (≤ 256x256 px
     * PNG under 64 KB is already lossless) and small enough to keep the
     * repeated dashboard renders fast.
     */
    private const MAX_AVATAR_BYTES = 1 * 1024 * 1024;

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly AgentPictureService $pictureService,
        private readonly MediaArchiveService $mediaArchive,
        private readonly MediaAllowedTypesService $allowedTypes,
        private readonly MimeSniffer $sniffer,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
    ) {}

    /**
     * POST /api/v1/agents/{id}/picture/image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', 'Agent not found.');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->error('BAD_REQUEST', 'No file uploaded under the "file" field.', Response::HTTP_BAD_REQUEST);
        }

        $bytes = $this->readFileBytes($file);
        $validationError = $this->validateUpload($file, $bytes, $agentId);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->performUpload($request, $file, $bytes, $agentId, $userId);
    }

    /**
     * Run the byte-path (MediaArchive → agent_pictures write) after the
     * controller has finished the input validations. Extracted from
     * `uploadImage()` so the controller stays under the 3-return ceiling.
     */
    private function performUpload(
        Request $request,
        UploadedFile $file,
        string $bytes,
        int $agentId,
        int $userId,
    ): JsonResponse {
        $clientName = $file->getClientOriginalName();
        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $this->sniffer->sniffFromBytes($bytes),
            filename: $clientName !== ''
                ? Utf8Sanitizer::scrubString($clientName)
                : null,
            userId: $userId,
            agentId: $agentId,
            uploadSource: 'avatar',
        ));

        $this->pictureService->attachImage($agentId, $asset);

        $fresh = $this->agentService->getAgent($agentId, $userId);
        return new JsonResponse([
            'data' => [
                'asset'  => $this->serializer->serialize($asset, $request->getSchemeAndHttpHost()),
                'agent'  => $fresh !== null ? AgentResource::toArray($fresh, null, null, $this->pictureService) : null,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Run the upload-side validations (file size, MIME allowlist) after
     * the controller has already confirmed the file is an UploadedFile and
     * read its bytes. Returns 4xx JsonResponse on failure, null on success.
     */
    private function validateUpload(UploadedFile $file, string $bytes, int $agentId): ?JsonResponse
    {
        $sizeError = $this->validateUploadSize($file);
        if ($sizeError !== null) {
            return $sizeError;
        }
        return $this->validateUploadMime($bytes, $agentId);
    }

    /**
     * Validate the upload's integrity + size. Returns 4xx JsonResponse on
     * failure, null on success. Extracted from validateUpload() so both
     * stay under the 3-return ceiling (S1142).
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
     * Validate the upload's MIME type against the agent's allowed-image
     * set. Returns 4xx JsonResponse on failure, null on success.
     */
    private function validateUploadMime(string $bytes, int $agentId): ?JsonResponse
    {
        $sniffedMime = $this->sniffer->sniffFromBytes($bytes);
        if (!$this->allowedTypes->isAllowed($sniffedMime, $agentId)) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                sprintf('MIME type "%s" is not in the upload allowlist.', $sniffedMime),
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }
        return null;
    }

    /**
     * Read the uploaded file's bytes; throw on read failure. The controller
     * path is the only caller (the multipart upload decoding), so the
     * exception bubbles up to the Symfony exception handler.
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
     * DELETE /api/v1/agents/{id}/picture/image
     */
    public function deleteImage(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', 'Agent not found.');
        }

        $this->pictureService->detachImage($agentId);

        $fresh = $this->agentService->getAgent($agentId, $userId);
        return new JsonResponse([
            'data' => [
                'agent' => $fresh !== null ? AgentResource::toArray($fresh, null, null, $this->pictureService) : null,
            ],
        ]);
    }
}
