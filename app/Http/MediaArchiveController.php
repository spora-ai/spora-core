<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeImmutable;
use Exception;
use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaType;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->buildListQuery($request);
        $page  = $this->mediaArchive->list($query);

        return new JsonResponse([
            'data' => [
                'assets'    => array_map(
                    static fn(MediaAsset $asset): array => self::serialize($asset),
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

        return new JsonResponse(['data' => self::serialize($asset)]);
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound();
        }
        if (!$this->canEdit($asset)) {
            return $this->forbidden();
        }

        $body = $this->jsonBody($request);
        $dirty = $this->extractUpdatableFields($body);

        $validation = $this->validateUpdatableFields($body);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        if ($dirty !== []) {
            $asset->fill($dirty);
            $asset->save();
        }

        return new JsonResponse(['data' => self::serialize($asset, $this->requestHost($request))]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractUpdatableFields(array $body): array
    {
        $dirty = [];
        foreach (['filename', 'tags', 'metadata', 'prompt'] as $field) {
            if (array_key_exists($field, $body)) {
                $dirty[$field] = $body[$field];
            }
        }
        if (array_key_exists('public_access_enabled', $body)) {
            $enabled = $body['public_access_enabled'];
            $dirty['public_access_token'] = $enabled === true ? bin2hex(random_bytes(32)) : null;
        }
        return $dirty;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateUpdatableFields(array $body): ?JsonResponse
    {
        if (array_key_exists('filename', $body)) {
            $filename = $body['filename'];
            if ($filename !== null && (!is_string($filename) || strlen($filename) > 255)) {
                return $this->badRequest('filename must be a string up to 255 characters.');
            }
        }
        if (array_key_exists('tags', $body) && $body['tags'] !== null && !is_array($body['tags'])) {
            return $this->badRequest('tags must be an array of strings.');
        }
        if (array_key_exists('metadata', $body) && $body['metadata'] !== null && !is_array($body['metadata'])) {
            return $this->badRequest('metadata must be an object.');
        }
        if (array_key_exists('prompt', $body) && $body['prompt'] !== null && !is_string($body['prompt'])) {
            return $this->badRequest('prompt must be a string.');
        }
        if (array_key_exists('public_access_enabled', $body) && !is_bool($body['public_access_enabled'])) {
            return $this->badRequest('public_access_enabled must be a boolean.');
        }
        return null;
    }

    public function destroy(string $id): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound();
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
        $asset->public_access_token = bin2hex(random_bytes(32));
        $asset->save();
        return new JsonResponse(['data' => self::serialize($asset, $this->requestHost($request))]);
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
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function requestHost(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    private function buildListQuery(Request $request): ListMediaQuery
    {
        $params = $request->query;

        return new ListMediaQuery(
            mediaType: $this->parseMediaType($params->get('type')),
            agentId: $this->parseAgentId($params->get('agent_id')),
            userId: $params->get('scope') === 'mine' ? $this->auth->currentUserId() : null,
            pluginSlug: $this->parseString($params->get('plugin_slug')),
            toolName: $this->parseString($params->get('tool_name')),
            from: $this->parseDate($params->get('from')),
            to: $this->parseDate($params->get('to')),
            search: $this->parseString($params->get('q')),
            sort: $this->parseSort($params->get('sort')),
            page: $this->parseInt($params->get('page'), 1),
            perPage: $this->parseInt($params->get('per_page'), ListMediaQuery::PER_PAGE_DEFAULT),
        );
    }

    private function parseMediaType(mixed $raw): ?MediaType
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return MediaType::tryFrom(strtolower($raw));
    }

    private function parseDate(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }

    private function parseAgentId(mixed $raw): ?int
    {
        if (!is_string($raw) || $raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function parseSort(mixed $raw): string
    {
        return is_string($raw) ? $raw : ListMediaQuery::SORT_CREATED_DESC;
    }

    private function parseString(mixed $raw): ?string
    {
        return is_string($raw) ? $raw : null;
    }

    private function parseInt(mixed $raw, int $default): int
    {
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(MediaAsset $asset, ?string $host = null): array
    {
        $row = [
            'id'                => $asset->id,
            'agent_id'          => $asset->agent_id,
            'task_id'           => $asset->task_id,
            'tool_call_id'      => $asset->tool_call_id,
            'user_id'           => $asset->user_id,
            'plugin_slug'       => $asset->plugin_slug,
            'tool_name'         => $asset->tool_name,
            'media_type'        => $asset->media_type,
            'mime_type'         => $asset->mime_type,
            'byte_size'         => $asset->byte_size,
            'width'             => $asset->width,
            'height'            => $asset->height,
            'duration_seconds'  => $asset->duration_seconds,
            'prompt'            => $asset->prompt,
            'filename'          => $asset->filename,
            'tags'              => $asset->tags,
            'metadata'          => $asset->metadata,
            'asset_url'         => $asset->publicUrl(),
            'source_url'        => $asset->source_url,
            'storage_mode'      => $asset->storage_mode,
            'upload_source'     => $asset->upload_source,
            'public_access_token' => $asset->public_access_token,
            'public_url'        => self::buildPublicUrl($asset, $host),
            'has_markdown'      => $asset->markdown_content !== null && $asset->markdown_content !== '',
            'created_at'        => $asset->created_at?->toIso8601String(),
            'updated_at'        => $asset->updated_at?->toIso8601String(),
        ];
        return $row;
    }

    private static function buildPublicUrl(MediaAsset $asset, ?string $host): ?string
    {
        if ($asset->public_access_token === null || $asset->public_access_token === '') {
            return null;
        }
        $base = $host !== null && $host !== ''
            ? rtrim($host, '/')
            : rtrim((string) ($_SERVER['HTTP_HOST'] ?? ''), '/');
        if ($base === '') {
            return null;
        }
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        return $scheme . '://' . $base . '/api/v1/public/media/' . $asset->id . '?token=' . $asset->public_access_token;
    }

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
