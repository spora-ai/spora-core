<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveRetention;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetKind;
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
 * `prompt`, `tags`, `metadata`, `folder_id` and `kind`. Bytes are
 * MIME-sniffed (the client header is never trusted), validated against
 * the dynamic allowlist computed by {@see MediaAllowedTypesService}, and
 * routed through the same {@see MediaArchiveService::ingest()} pipeline
 * that tools use.
 *
 * `ingest()` also mints the `md` derivative of a binary document, so a
 * PDF is readable by the LLM with no second operator action. Best effort:
 * a producer failure leaves the upload intact.
 *
 * `folder_id` files the upload in a folder of the target principal and
 * `kind` marks it. The mark is advisory: a mark the operator chose is
 * stored as given, and a value outside the mark's vocabulary is refused
 * with a 400 before the bytes reach the AssetStore. The folder is
 * checked against the target principal and against the folder's filename
 * rule, so an upload that would collide is a 422 rather than a database
 * error.
 */
final class MediaUploadController
{
    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly MediaArchiveRetention $retention,
        private readonly MediaAllowedTypesService $allowedTypes,
        private readonly AuthService $auth,
        private readonly PrincipalResolver $principalResolver,
        private readonly MimeSniffer $sniffer,
        private readonly Config $config,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
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

        $agentId = $this->readId($request->request->get('agent_id'));
        $principalId = $this->readId($request->request->get('principal_id'));

        $prompt = $request->request->get('prompt');
        $isTemporary = $this->parseBool($request->request->get('is_temporary'));
        $folderId = $this->readString($request->request->get('folder_id'));
        $kind = $this->readString($request->request->get('kind'));

        $asset = $this->ingestChecked(new MediaIngestRequest(
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
            isTemporary: $isTemporary,
            folderId: $folderId,
            kind: $kind,
        ), $sniffedMime, $userId);
        if ($asset instanceof JsonResponse) {
            return $asset;
        }

        // Real-time retention purge: only when the caller flagged the
        // row as temp AND attached an agent_id. Direct uploads to the
        // dashboard never hit this path (no agent context). Errors are
        // swallowed because the upload itself succeeded — the purge is
        // an opportunistic sweep, not a precondition for the response.
        if ($isTemporary && $agentId !== null) {
            $this->retention->enforceTempRetention($userId, $agentId, $asset->id);
        }

        return new JsonResponse(
            ['data' => $this->serializer->serialize($asset, $this->config->getString('SPORA_APP_URL'))],
            Response::HTTP_CREATED,
        );
    }

    /**
     * The checks the request must pass, and then the ingest.
     *
     * The allowlist is checked against the sniffed MIME, never the
     * client header, and the mark's vocabulary is checked before the
     * bytes reach the AssetStore.
     */
    private function ingestChecked(MediaIngestRequest $request, string $sniffedMime, int $userId): MediaAsset|JsonResponse
    {
        // The allowlist must use the sniffed MIME, never the client header.
        $error = $this->checkMimeAllowed($sniffedMime, $request->agentId)
            ?? $this->checkPrincipalAllowed($request->principalId, $userId)
            ?? $this->checkKindAllowed($request->kind);
        if ($error !== null) {
            return $error;
        }

        return $this->ingest($request);
    }

    /**
     * The ingest, or the error the request must answer.
     *
     * Split out of {@see store()} so that method stays under the
     * 3-return brain-overload ceiling.
     */
    private function ingest(MediaIngestRequest $request): MediaAsset|JsonResponse
    {
        try {
            return $this->mediaArchive->ingest($request);
        } catch (MediaFolderNotFoundException) {
            // The folder is the target tenant's, and this caller cannot
            // see it. The same answer covers a folder that does not
            // exist — the caller has no business telling the two apart.
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'UNKNOWN_FOLDER',
                'That folder does not exist.',
            );
        } catch (MediaFolderUniquenessException $e) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'FOLDER_FILENAME_TAKEN',
                $e->getMessage(),
            );
        }
    }

    /** A non-empty string from the form, or null. */
    private function readString(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);

        return $value === '' ? null : $value;
    }

    /** A numeric id from the form, or null. */
    private function readId(mixed $raw): ?int
    {
        if (!is_string($raw) || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * The mark is advisory: a mark the operator chose is never refused.
     *
     * A value outside the vocabulary is refused, before the bytes reach
     * the AssetStore — the column cannot hold it and the listing's filter
     * cannot select it, so storing it would only produce a row nobody
     * can find.
     */
    private function checkKindAllowed(?string $kind): ?JsonResponse
    {
        if ($kind === null || in_array($kind, MediaAssetKind::ALL, true)) {
            return null;
        }

        return $this->error(
            Response::HTTP_BAD_REQUEST,
            'INVALID_KIND',
            sprintf('kind must be one of: %s.', implode(', ', MediaAssetKind::ALL)),
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

    /**
     * Coerce the multipart `is_temporary` flag to a strict bool. Forms
     * ship 'true' / 'false' / '1' / '0'; `FILTER_VALIDATE_BOOLEAN`
     * normalises both to a real bool regardless of transport. Anything
     * else (missing key, null, typo) falls back to `false` so a
     * permissive caller doesn't accidentally trip the retention purge.
     */
    private function parseBool(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }
        $normalised = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $normalised ?? false;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );
    }
}
