<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
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

        $fileError = $this->validateUploadedFile($request);
        if ($fileError !== null) {
            return $fileError;
        }
        $file = $request->files->get('file');
        assert($file instanceof UploadedFile);

        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) {
            return $this->error('READ_FAILED', 'Could not read uploaded file.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $sniffedMime = $this->sniffer->sniffFromBytes($bytes);
        if (!$this->allowedTypes->isAllowed($sniffedMime, $agentId)) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                sprintf('MIME type "%s" is not in the upload allowlist.', $sniffedMime),
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $clientName = $file->getClientOriginalName();
        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $sniffedMime,
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
     * Validate the multipart upload request before the controller reads the
     * file body. Centralises the 4 guard clauses so `uploadImage()` stays
     * under the cognitive-complexity ceiling.
     */
    private function validateUploadedFile(Request $request): ?JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->error('BAD_REQUEST', 'No file uploaded under the "file" field.', Response::HTTP_BAD_REQUEST);
        }
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
