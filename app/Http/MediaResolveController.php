<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch endpoint for resolving Media Archive UUIDs referenced from a
 * task's history rows (`task_history.attachments[*].media_id`) into the
 * wire-shape `MediaAsset` payload the chat list needs to render chips.
 *
 * Why a dedicated batch endpoint: a task with N turns × M attachments
 * would otherwise need one `GET /media/{id}` per slot on every poll —
 * classic N+1. The chat list batches unknown IDs through here once per
 * page render and caches the result in memory.
 *
 * Existence-hiding: a foreign id is silently dropped from the response
 * (no 404, no 403) so the chat cannot probe for archive rows it does
 * not own. The visibility union — direct `user_id` match OR
 * `PrincipalResolver::isVisibleTo($asset.agent_id)` — mirrors the
 * {@see AssetController} ownership check so an `asset_url` rendered by
 * the chat list always resolves when the row is here.
 */
final class MediaResolveController
{
    /**
     * Hard cap on IDs per request. Defends against the obvious
     * mistake of forwarding the entire chat history's `attachments`
     * list (each task can in theory reference thousands of rows),
     * and keeps the underlying `WHERE id IN (…)` bounded.
     */
    private const MAX_IDS_PER_REQUEST = 64;

    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(includeDerivatives: false),
    ) {}

    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(
                    property: 'ids',
                    type: 'array',
                    items: new OA\Items(type: 'string', format: 'uuid'),
                    maxItems: self::MAX_IDS_PER_REQUEST,
                    description: 'Media Archive UUIDs. Up to 64 per request.',
                ),
            ],
        ),
    )]
    public function resolve(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->error(
                Response::HTTP_UNAUTHORIZED,
                'UNAUTHORIZED',
                'You must be logged in to resolve media.',
            );
        }

        $ids = $this->parseIds($request);
        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $resolved = $this->mediaArchive->resolveMany($ids, $userId, $this->auth->isAdmin());

        return new JsonResponse([
            'data' => [
                'assets' => array_map(
                    fn(MediaAsset $asset): array => $this->serializer->serialize($asset),
                    $resolved,
                ),
            ],
        ]);
    }

    /**
     * Parse and validate the `ids` body field. Returns the validated list
     * on success, or a 422 `JsonResponse` on any validation failure.
     *
     * The three validation steps each return `null` on success or a 422
     * `JsonResponse` on failure; the first non-null error wins and the
     * method has a single error-return path so it stays under the Sonar
     * S1142 return-count cap.
     *
     * @return list<string>|JsonResponse
     */
    private function parseIds(Request $request): array|JsonResponse
    {
        $body = $this->decodeJsonBody($request);
        $ids = $body instanceof JsonResponse
            ? $body
            : $this->extractIdList($body);
        if ($ids instanceof JsonResponse) {
            return $ids;
        }
        $checked = $this->validateUuidList($ids);
        return $checked instanceof JsonResponse ? $checked : array_values($checked);
    }

    /**
     * Decode the request body as JSON. Returns a 422 `JsonResponse`
     * on parse failure or a non-object body; the decoded value otherwise.
     */
    private function decodeJsonBody(Request $request): array|JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'VALIDATION_ERROR',
                'Request body must be valid JSON.',
            );
        }
        if (!is_array($body) || !array_key_exists('ids', $body)) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'VALIDATION_ERROR',
                'Body must include an "ids" array.',
            );
        }
        return $body;
    }

    /**
     * Pull the `ids` field out of the decoded body and enforce the
     * presence + cap constraints. Returns the raw list on success, or
     * a 422 `JsonResponse` when the field is missing, not an array,
     * empty, or over the cap.
     *
     * @return list<mixed>|JsonResponse
     */
    private function extractIdList(array $body): array|JsonResponse
    {
        $raw = $body['ids'];
        if (!is_array($raw) || $raw === []) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'VALIDATION_ERROR',
                '"ids" must be a non-empty array.',
            );
        }
        if (count($raw) > self::MAX_IDS_PER_REQUEST) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'VALIDATION_ERROR',
                sprintf('At most %d ids per request.', self::MAX_IDS_PER_REQUEST),
            );
        }
        return array_values($raw);
    }

    /**
     * Walk the raw id list, dropping duplicates (preserving first
     * occurrence) and verifying each entry is a UUID. Returns the
     * deduped list on success, or a 422 `JsonResponse` when any entry
     * is not a UUID string.
     *
     * @param  list<mixed> $raw
     * @return array<string, string>|JsonResponse
     */
    private function validateUuidList(array $raw): array|JsonResponse
    {
        $ids = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || preg_match(self::UUID_REGEX, $entry) !== 1) {
                return $this->error(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'VALIDATION_ERROR',
                    'Every id must be a UUID string.',
                );
            }
            // Preserve insertion order; dedupe via array_key_exists so
            // duplicates resolve once but stay in the input position.
            if (!array_key_exists($entry, $ids)) {
                $ids[$entry] = $entry;
            }
        }
        return $ids;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
        );
    }
}
