<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MimeSniffer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multipart upload endpoint for the Media Archive.
 *
 * - POST /api/v1/media
 *
 * Accepts `multipart/form-data` with a `file` part plus optional
 * `prompt`, `tags`, `metadata`. Bytes are MIME-sniffed (the client
 * header is never trusted), validated against the dynamic allowlist
 * computed by {@see MediaAllowedTypesService}, and routed through
 * the same `MediaArchiveService::ingest()` pipeline that tools use.
 *
 * The conversion pipeline runs as part of `ingest()` and populates
 * `markdown_content` when a registered converter handles the asset.
 */
final class MediaUploadController
{
    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly MediaAllowedTypesService $allowedTypes,
        private readonly AuthService $auth,
        private readonly MimeSniffer $sniffer,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateUpload($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        [$file, $bytes, $userId] = $validated;
        $sniffedMime = $this->sniffer->sniffFromBytes($bytes);

        // The allowlist must use the sniffed MIME, never the client header.
        $agentIdRaw = $request->request->get('agent_id');
        $agentId = is_string($agentIdRaw) && ctype_digit($agentIdRaw) ? (int) $agentIdRaw : null;
        if (!$this->allowedTypes->isAllowed($sniffedMime, $agentId)) {
            return $this->error(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'UNSUPPORTED_MEDIA_TYPE',
                sprintf('MIME type "%s" is not in the upload allowlist.', $sniffedMime),
            );
        }

        $prompt = $request->request->get('prompt');
        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $sniffedMime,
            filename: $file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : null,
            userId: $userId,
            prompt: is_string($prompt) ? $prompt : null,
            tags: $this->parseJsonArray($request->request->get('tags')),
            metadata: $this->parseJsonObject($request->request->get('metadata')),
            uploadSource: 'upload',
        ));

        return new JsonResponse(
            ['data' => $this->serializer->serialize($asset, $request->getSchemeAndHttpHost())],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @return array{0: UploadedFile, 1: string, 2: int}|JsonResponse
     */
    private function validateUpload(Request $request): array|JsonResponse
    {
        $userId = $this->auth->currentUserId();
        $file = $request->files->get('file');
        $error = $this->validateUploadError($userId, $file);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        assert($file instanceof UploadedFile);
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) {
            return $this->error(Response::HTTP_INTERNAL_SERVER_ERROR, 'READ_FAILED', 'Could not read uploaded file.');
        }

        return [$file, $bytes, $userId];
    }

    /**
     * @return JsonResponse|null
     */
    private function validateUploadError(?int $userId, mixed $file): ?JsonResponse
    {
        $error = null;
        if ($userId === null) {
            $error = $this->error(Response::HTTP_UNAUTHORIZED, 'UNAUTHORIZED', 'You must be logged in to upload.');
        } elseif (!$file instanceof UploadedFile) {
            $error = $this->error(Response::HTTP_BAD_REQUEST, 'BAD_REQUEST', 'No file uploaded under the "file" field.');
        } elseif (!$file->isValid()) {
            $error = $this->error(Response::HTTP_BAD_REQUEST, 'BAD_REQUEST', 'Upload failed: ' . $file->getErrorMessage());
        }

        return $error;
    }

    /** @return array<string>|null */
    private function parseJsonArray(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    /** @return array<string, mixed>|null */
    private function parseJsonObject(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );
    }
}
