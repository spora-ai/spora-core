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
use Spora\Services\PrincipalResolver;
use Spora\Services\Text\Utf8Sanitizer;
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
        private readonly PrincipalResolver $principalResolver,
        private readonly MimeSniffer $sniffer,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
        private readonly array $config = [],
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateUpload($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        /** @var UploadedFile $file */
        [$file, $bytes, $userId] = $validated;
        $clientName = $file->getClientOriginalName();
        $sniffedMime = $this->sniffer->sniffFromBytes($bytes, $clientName);

        // The allowlist must use the sniffed MIME, never the client header.
        $agentIdRaw = $request->request->get('agent_id');
        $agentId = is_string($agentIdRaw) && ctype_digit($agentIdRaw) ? (int) $agentIdRaw : null;
        $principalIdRaw = $request->request->get('principal_id');
        $principalId = is_string($principalIdRaw) && ctype_digit($principalIdRaw)
            ? (int) $principalIdRaw
            : null;

        $error = $this->checkMimeAllowed($sniffedMime, $agentId)
            ?? $this->checkPrincipalAllowed($principalId, $userId);
        if ($error !== null) {
            return $error;
        }

        $prompt = $request->request->get('prompt');
        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $sniffedMime,
            filename: $clientName !== ''
                ? Utf8Sanitizer::scrubString($clientName)
                : null,
            userId: $userId,
            principalId: $principalId,
            agentId: $agentId,
            prompt: is_string($prompt) ? Utf8Sanitizer::scrubString($prompt) : null,
            tags: Utf8Sanitizer::scrub($this->parseJsonArray($request->request->get('tags'))),
            metadata: Utf8Sanitizer::scrub($this->parseJsonObject($request->request->get('metadata'))),
            uploadSource: 'upload',
        ));

        return new JsonResponse(
            ['data' => $this->serializer->serialize($asset, (string) ($this->config['app_url'] ?? ''))],
            Response::HTTP_CREATED,
        );
    }

    private function checkMimeAllowed(string $sniffedMime, ?int $agentId): ?JsonResponse
    {
        if ($this->allowedTypes->isAllowed($sniffedMime, $agentId)) {
            return null;
        }
        return $this->error(
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            'UNSUPPORTED_MEDIA_TYPE',
            sprintf('MIME type "%s" is not in the upload allowlist.', $sniffedMime),
        );
    }

    /**
     * Intersect the request's `principal_id` with the caller's visible
     * principals — typo tolerance and existence-hiding in one. A foreign
     * id is rejected with 403 so the operator can't silently stamp an
     * asset into someone else's principal.
     */
    private function checkPrincipalAllowed(?int $principalId, int $userId): ?JsonResponse
    {
        if ($principalId === null) {
            return null;
        }
        $visible = $this->principalResolver->visiblePrincipalIds($userId);
        if (in_array($principalId, $visible, true)) {
            return null;
        }
        return $this->error(
            Response::HTTP_FORBIDDEN,
            'FORBIDDEN_PRINCIPAL',
            'You can only upload into a principal you belong to.',
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
