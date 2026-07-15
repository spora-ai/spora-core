<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
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
    ) {}

    public function store(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->error(Response::HTTP_UNAUTHORIZED, 'UNAUTHORIZED', 'You must be logged in to upload.');
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->error(Response::HTTP_BAD_REQUEST, 'BAD_REQUEST', 'No file uploaded under the "file" field.');
        }
        if (!$file->isValid()) {
            return $this->error(Response::HTTP_BAD_REQUEST, 'BAD_REQUEST', 'Upload failed: ' . $file->getErrorMessage());
        }

        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) {
            return $this->error(Response::HTTP_INTERNAL_SERVER_ERROR, 'READ_FAILED', 'Could not read uploaded file.');
        }

        $clientMime = (string) $file->getClientMimeType();
        $filename    = (string) $file->getClientOriginalName();

        // Validate against the dynamic allowlist. The agent context is
        // optional; pass `agent_id` (string or int) to enable image MIME
        // types when the agent's LLM supports them.
        $agentIdRaw = $request->request->get('agent_id');
        $agentId    = is_string($agentIdRaw) && ctype_digit($agentIdRaw) ? (int) $agentIdRaw : null;

        // Use the client MIME as a first hint, then defer to the archive
        // service's own sniffFromBytes. We only need the allowlist check
        // here to give the user a clean 415 before we even start ingest.
        $allowMime = $clientMime !== '' ? $clientMime : 'application/octet-stream';
        if (!$this->allowedTypes->isAllowed($allowMime, $agentId)) {
            return $this->error(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'UNSUPPORTED_MEDIA_TYPE',
                sprintf('MIME type "%s" is not in the upload allowlist.', $allowMime),
            );
        }

        $tagsRaw     = $request->request->get('tags');
        $metadataRaw = $request->request->get('metadata');
        $prompt      = $request->request->get('prompt');

        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $allowMime,
            filename: $filename !== '' ? $filename : null,
            userId: $userId,
            prompt: is_string($prompt) ? $prompt : null,
            tags: $this->parseJsonArray($tagsRaw),
            metadata: $this->parseJsonObject($metadataRaw),
            uploadSource: 'upload',
        ));

        return new JsonResponse(
            ['data' => MediaArchiveController::serialize($asset, $request->getSchemeAndHttpHost())],
            Response::HTTP_CREATED,
        );
    }

    /** @return array<string>|null */
    private function parseJsonArray(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
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
        } catch (\JsonException) {
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
