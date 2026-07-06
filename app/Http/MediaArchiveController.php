<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeImmutable;
use Exception;
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
 * - DELETE /api/v1/media/{id}  — remove a row from the archive
 *
 * Mutations are intentionally absent: archiving is opt-in per tool call,
 * the plugin's `embedHex()`/`mediaArchive()->ingest()` path is the only
 * way to add rows. The DELETE here is a manual cleanup escape hatch.
 *
 * Auth is enforced by the route's middleware (AuthMiddleware +
 * CsrfMiddleware); the controller does not duplicate the check.
 */
final class MediaArchiveController
{
    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
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

    public function destroy(string $id): JsonResponse
    {
        $asset = $this->mediaArchive->find($id);
        if ($asset === null) {
            return $this->notFound();
        }
        $this->mediaArchive->delete($id);

        return new JsonResponse(['data' => ['deleted' => true, 'id' => $id]]);
    }

    private function buildListQuery(Request $request): ListMediaQuery
    {
        $params = $request->query;

        $mediaTypeRaw = $params->get('type');
        $mediaType = null;
        if (is_string($mediaTypeRaw) && $mediaTypeRaw !== '') {
            $mediaType = MediaType::tryFrom(strtolower($mediaTypeRaw));
        }

        $from = null;
        $fromRaw = $params->get('from');
        if (is_string($fromRaw) && $fromRaw !== '') {
            try {
                $from = new DateTimeImmutable($fromRaw);
            } catch (Exception) {
                $from = null;
            }
        }

        $to = null;
        $toRaw = $params->get('to');
        if (is_string($toRaw) && $toRaw !== '') {
            try {
                $to = new DateTimeImmutable($toRaw);
            } catch (Exception) {
                $to = null;
            }
        }

        $agentIdRaw = $params->get('agent_id');
        $agentId = null;
        if (is_string($agentIdRaw) && $agentIdRaw !== '' && ctype_digit($agentIdRaw)) {
            $agentId = (int) $agentIdRaw;
        }

        $sort = is_string($params->get('sort')) ? (string) $params->get('sort') : ListMediaQuery::SORT_CREATED_DESC;

        $pageRaw = $params->get('page');
        $page = is_string($pageRaw) && ctype_digit($pageRaw) ? (int) $pageRaw : 1;

        $perPageRaw = $params->get('per_page');
        $perPage = is_string($perPageRaw) && ctype_digit($perPageRaw) ? (int) $perPageRaw : ListMediaQuery::PER_PAGE_DEFAULT;

        return new ListMediaQuery(
            mediaType: $mediaType,
            agentId: $agentId,
            pluginSlug: is_string($params->get('plugin_slug')) ? (string) $params->get('plugin_slug') : null,
            toolName: is_string($params->get('tool_name')) ? (string) $params->get('tool_name') : null,
            from: $from,
            to: $to,
            search: is_string($params->get('q')) ? (string) $params->get('q') : null,
            sort: $sort,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(MediaAsset $asset): array
    {
        return [
            'id'              => $asset->id,
            'agent_id'        => $asset->agent_id,
            'task_id'         => $asset->task_id,
            'tool_call_id'    => $asset->tool_call_id,
            'plugin_slug'     => $asset->plugin_slug,
            'tool_name'       => $asset->tool_name,
            'media_type'      => $asset->media_type,
            'mime_type'       => $asset->mime_type,
            'byte_size'       => $asset->byte_size,
            'width'           => $asset->width,
            'height'          => $asset->height,
            'duration_seconds' => $asset->duration_seconds,
            'prompt'          => $asset->prompt,
            'tags'            => $asset->tags,
            'metadata'        => $asset->metadata,
            'asset_url'       => $asset->asset_url,
            'source_url'      => $asset->source_url,
            'storage_mode'    => $asset->storage_mode,
            'created_at'      => $asset->created_at?->toIso8601String(),
            'updated_at'      => $asset->updated_at?->toIso8601String(),
        ];
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Media asset not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
