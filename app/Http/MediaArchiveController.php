<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQueryBuilder;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
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
            $editable->fill($dirty);
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
     * @param array<string, mixed> $body
     */
    private function validateUpdatableFields(array $body): ?JsonResponse
    {
        $message = null;
        if (array_key_exists('filename', $body)) {
            $filename = $body['filename'];
            if ($filename !== null && (!is_string($filename) || strlen($filename) > 255)) {
                $message = 'filename must be a string up to 255 characters.';
            }
        }
        if ($message === null
            && array_key_exists('tags', $body)
            && $body['tags'] !== null
            && !is_array($body['tags'])
        ) {
            $message = 'tags must be an array of strings.';
        }
        if ($message === null
            && array_key_exists('metadata', $body)
            && $body['metadata'] !== null
            && !is_array($body['metadata'])
        ) {
            $message = 'metadata must be an object.';
        }
        if ($message === null
            && array_key_exists('prompt', $body)
            && $body['prompt'] !== null
            && !is_string($body['prompt'])
        ) {
            $message = 'prompt must be a string.';
        }
        if ($message === null
            && array_key_exists('markdown_content', $body)
            && $body['markdown_content'] !== null
            && !is_string($body['markdown_content'])
        ) {
            $message = 'markdown_content must be a string.';
        }
        if ($message === null
            && array_key_exists('public_access_enabled', $body)
            && !is_bool($body['public_access_enabled'])
        ) {
            $message = 'public_access_enabled must be a boolean.';
        }

        return $message === null ? null : $this->badRequest($message);
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
