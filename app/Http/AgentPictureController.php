<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Models\Agent;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentResource;
use Spora\Services\AgentResourceContext;
use Spora\Services\AgentServiceInterface;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MimeSniffer;
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
 * The shared multipart-upload pipeline (size cap, MIME allowlist, byte
 * decode verification) lives in {@see AvatarUploadSupport} so the
 * Agent and Group controllers can share it without duplication.
 */
final class AgentPictureController
{
    use AvatarUploadSupport;
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly AgentPictureService $pictureService,
        private readonly MediaArchiveService $mediaArchive,
        private readonly MimeSniffer $sniffer,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
        private readonly array $config = [],
    ) {}

    /**
     * POST /api/v1/agents/{id}/picture/image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $agentError = $this->resolveAgentForUpload($agentId, $userId);
        if ($agentError !== null) {
            return $agentError;
        }

        $prepared = $this->prepareUpload($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        return $this->performUpload($prepared['file'], $prepared['bytes'], $agentId, $userId);
    }

    /**
     * Look up the agent for an upload. Returns null on success, or a 4xx
     * JsonResponse on failure. Extracted from {@see uploadImage()} so the
     * controller stays under the 3-return ceiling (S1142).
     */
    private function resolveAgentForUpload(int $agentId, int $userId): ?JsonResponse
    {
        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', 'Agent not found.');
        }
        return null;
    }

    /**
     * Run the byte-path (MediaArchive → agent_pictures write) after the
     * controller has finished the input validations. Extracted from
     * `uploadImage()` so the controller stays under the 3-return ceiling.
     */
    private function performUpload(
        UploadedFile $file,
        string $bytes,
        int $agentId,
        int $userId,
    ): JsonResponse {
        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $this->sniffer->sniffFromBytes($bytes),
            filename: $this->sanitisedClientName($file),
            userId: $userId,
            agentId: $agentId,
            uploadSource: 'avatar',
        ));

        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent instanceof Agent) {
            $this->pictureService->attachImage($agent, $asset);
        }

        $fresh = $this->agentService->getAgent($agentId, $userId);
        return new JsonResponse([
            'data' => [
                'asset'  => $this->serializer->serialize($asset, (string) ($this->config['app_url'] ?? '')),
                'agent'  => $fresh !== null ? AgentResource::toArray($fresh, new AgentResourceContext(pictureService: $this->pictureService)) : null,
            ],
        ], Response::HTTP_CREATED);
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
                'agent' => $fresh !== null ? AgentResource::toArray($fresh, new AgentResourceContext(pictureService: $this->pictureService)) : null,
            ],
        ]);
    }
}
