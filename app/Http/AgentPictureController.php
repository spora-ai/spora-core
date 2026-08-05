<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\AvatarFileReadFailedException;
use Spora\Models\Agent;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentResource;
use Spora\Services\AgentServiceInterface;
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

    /**
     * Avatar MIME allowlist. Distinct from {@see MediaAllowedTypesService}
     * because avatars are *always* raster images, regardless of whether
     * the agent's LLM accepts image input. Pulling the LLM capability
     * into this check was the original bug: a text-only agent would
     * reject otherwise-valid PNG/JPEG/WebP uploads, while a PDF or JSON
     * byte stream could pass whenever the allowlist happened to include
     * it. Bytes are sniffed (the client `Content-Type` header is ignored),
     * then decoded to confirm PHP can actually render them — so a
     * `image/png` Content-Type header with non-image bytes is rejected.
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
        if ($agentError instanceof JsonResponse) {
            return $agentError;
        }

        $prepared = $this->prepareUpload($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        return $this->performUpload($prepared['file'], $prepared['bytes'], $agentId, $userId);
    }

    /**
     * Validate the uploaded file (size + declared mime from the multipart
     * envelope), then read its bytes for MIME sniffing and image-decoding
     * verification. Returns the parsed file + bytes on success or a 4xx
     * JsonResponse on any failure. The cheap, non-byte checks run *before*
     * `file_get_contents()` so the 1 MiB cap actually bounds upload-path
     * memory consumption. Agent ownership is checked in the public
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
     * upload-path memory consumption. Extracted from {@see prepareUpload()}
     * so that helper stays under the 3-return ceiling (S1142).
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
     * on success or a 4xx JsonResponse on the first failure. Extracted
     * from {@see prepareUpload()} so that helper stays under the 3-return
     * ceiling (S1142).
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

        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent instanceof Agent) {
            $this->pictureService->attachImage($agent, $asset);
        }

        $fresh = $this->agentService->getAgent($agentId, $userId);
        return new JsonResponse([
            'data' => [
                'asset'  => $this->serializer->serialize($asset, (string) ($this->config['app_url'] ?? '')),
                'agent'  => $fresh !== null ? AgentResource::toArray($fresh, null, null, $this->pictureService) : null,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Validate the upload's integrity + declared size (from the multipart
     * envelope). Returns 4xx JsonResponse on failure, null on success.
     * Runs *before* {@see readFileBytes()} so the size cap actually
     * bounds upload-path memory consumption; {@see prepareUpload()}
     * re-checks the actual byte length afterwards.
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
     * success. Avatars are always raster images; see {@see ALLOWED_AVATAR_MIMES}.
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
        // Defence in depth: a non-image byte stream with a `image/png`
        // header should never get past this gate. PHP's decoder only
        // succeeds for actual images in the raster allowlist.
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
