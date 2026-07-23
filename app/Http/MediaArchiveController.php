<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQueryBuilder;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\Text\Utf8Sanitizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST surface for the Media Archive.
 *
 * - GET    /api/v1/media       — paginated list with filters
 * - GET    /api/v1/media/{id}  — single asset detail
 * - PATCH  /api/v1/media/{id}  — edit metadata, filename, public sharing
 * - DELETE /api/v1/media/{id}  — remove a row from the archive
 *
 * Mutations beyond DELETE were previously absent because archiving was
 * opt-in per tool call. The upload pipeline (MediaUploadController)
 * adds rows; PATCH here lets the user rename, edit metadata, and
 * toggle the public-access token from the Media Archive detail page.
 *
 * Auth is enforced by the route's middleware (AuthMiddleware +
 * CsrfMiddleware); the controller does not duplicate the check.
 * PATCH also checks that the row belongs to the requesting user.
 */
final class MediaArchiveController
{
    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ListMediaQueryBuilder::fromRequest($request, $this->auth->currentUserId());
        $page  = $this->mediaArchive->list($query);

        return new JsonResponse([
            'data' => [
                'assets'    => array_map(
                    fn(MediaAsset $asset): array => $this->serializer->serialize($asset),
                    $page->items(),
                ),
                'page'      => $page->currentPage(),
                'perPage'   => $page->perPage(),
                'total'     => $page->total(),
                'lastPage'  => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $this->serializer->serialize($asset)]);
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $editable = $this->findEditableAsset($id);
        if ($editable instanceof JsonResponse) {
            return $editable;
        }

        $body = $this->jsonBody($request);
        $validation = $this->validateUpdatableFields($body);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        $dirty = $this->extractUpdatableFields($body);
        if ($dirty !== []) {
            $editable->fill(Utf8Sanitizer::scrub($dirty));
            $editable->save();
        }

        return new JsonResponse(['data' => $this->serializer->serialize($editable, $this->requestHost($request))]);
    }

    private function findEditableAsset(string $id): MediaAsset|JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound();
        }
        if (!$this->canEdit($asset)) {
            return $this->forbidden();
        }

        return $asset;
    }


    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractUpdatableFields(array $body): array
    {
        $dirty = [];
        foreach (['filename', 'tags', 'metadata', 'prompt', 'markdown_content'] as $field) {
            if (array_key_exists($field, $body)) {
                $dirty[$field] = $body[$field];
            }
        }
        if (array_key_exists('public_access_enabled', $body)) {
            $enabled = $body['public_access_enabled'];
            $dirty['public_access_token'] = $enabled === true ? MediaArchiveService::mintPublicAccessToken() : null;
        }
        return $dirty;
    }

    /**
     * Run each per-field validator in order; the first one that produces
     * an error message short-circuits the response. Per-field checks live
     * in their own helpers so the orchestrator stays under SonarQube's
     * 15 cognitive-complexity threshold — each helper is a free method
     * call from this scope.
     *
     * @param array<string, mixed> $body
     */
    private function validateUpdatableFields(array $body): ?JsonResponse
    {
        $messages = [
            $this->validateFilenameField($body),
            $this->validateArrayField($body, 'tags', 'tags must be an array of strings.'),
            $this->validateArrayField($body, 'metadata', 'metadata must be an object.'),
            $this->validateStringField($body, 'prompt', 'prompt must be a string.'),
            $this->validateStringField($body, 'markdown_content', 'markdown_content must be a string.'),
            $this->validateBoolField($body, 'public_access_enabled', 'public_access_enabled must be a boolean.'),
        ];
        foreach ($messages as $message) {
            if ($message !== null) {
                return $this->badRequest($message);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateFilenameField(array $body): ?string
    {
        if (!array_key_exists('filename', $body)) {
            return null;
        }
        $filename = $body['filename'];
        if ($filename !== null && (!is_string($filename) || strlen($filename) > 255)) {
            return 'filename must be a string up to 255 characters.';
        }
        return null;
    }

    /**
     * Shared validator for tags and metadata: both reject non-null
     * non-array payloads.
     *
     * @param array<string, mixed> $body
     */
    private function validateArrayField(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if ($value !== null && !is_array($value)) {
            return $errorMessage;
        }
        return null;
    }

    /**
     * Shared validator for prompt and markdown_content: both reject
     * non-null non-string payloads.
     *
     * @param array<string, mixed> $body
     */
    private function validateStringField(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $value = $body[$field];
        if ($value !== null && !is_string($value)) {
            return $errorMessage;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateBoolField(array $body, string $field, string $errorMessage): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        if (!is_bool($body[$field])) {
            return $errorMessage;
        }
        return null;
    }

    public function destroy(string $id): JsonResponse
    {
        $editable = $this->findEditableAsset($id);
        if ($editable instanceof JsonResponse) {
            return $editable;
        }

        $this->mediaArchive->delete($id);

        return new JsonResponse(['data' => ['deleted' => true, 'id' => $id]]);
    }

    /**
     * Rotate the public-access token on a media row.
     *
     * POST /api/v1/media/{id}/public-token/refresh
     */
    public function refreshPublicToken(string $id, Request $request): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound();
        }
        if (!$this->canEdit($asset)) {
            return $this->forbidden();
        }
        $asset->public_access_token = MediaArchiveService::mintPublicAccessToken();
        $asset->save();
        return new JsonResponse(['data' => $this->serializer->serialize($asset, $this->requestHost($request))]);
    }

    private function canEdit(MediaAsset $asset): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }
        $userId = $this->auth->currentUserId();
        return $userId !== null && $asset->user_id !== null && (int) $asset->user_id === $userId;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function requestHost(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }


    /**
     */
    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Media asset not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'FORBIDDEN', 'message' => 'You do not own this media asset.']],
            Response::HTTP_FORBIDDEN,
        );
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'BAD_REQUEST', 'message' => $message]],
            Response::HTTP_BAD_REQUEST,
        );
    }
}
