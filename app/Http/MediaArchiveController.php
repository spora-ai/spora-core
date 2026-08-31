<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\ListMediaQueryBuilder;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST surface for the Media Archive — core-only.
 *
 * Hosts `GET /api/v1/media` (the composer picker + dashboard list). The
 * plugin-only admin surface (`show`/`update`/`destroy`/
 * `refreshPublicToken`) moved to
 * `spora-plugin-media-archive/src/Http/MediaArchiveAdminController` in
 * `feat/media-principal-coverage` so the Media Archive plugin owns its
 * CRUD end-to-end, mirroring the `spora-plugin-memories` pattern.
 *
 * The two derivative endpoints (`POST /media/{id}/derivatives`,
 * `GET /media/{id}/derivatives/options`) live in `MediaDerivativeController`
 * / `MediaDerivativeOptionsController` because they are generic and have
 * multiple potential consumers — same architectural reason
 * `GET /api/v1/media/allowed-types` stays in core.
 *
 * Auth is enforced by the route's middleware (AuthMiddleware +
 * CsrfMiddleware); the controller does not duplicate the check.
 */
final class MediaArchiveController
{
    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly AuthService $auth,
        private readonly MediaAssetSerializer $serializer = new MediaAssetSerializer(),
        private readonly PrincipalResolver $principalResolver = new PrincipalResolver(),
    ) {}

    /**
     * GET /api/v1/media — paginated list with filters.
     *
     * `?principal_id[]=` is the dashboard-style scope filter consumed by
     * the Media Archive plugin's `ALL / My Media / Group X` chip row.
     * Repeated keys (the array-bracket form so PHP's parse_str preserves
     * every value) carry principal ids the caller wants to scope to.
     * The controller intersects the values with the caller's
     * `PrincipalResolver::visiblePrincipalIds()` so an out-of-scope id
     * is silently dropped — typo tolerance + existence-hiding in one.
     */
    #[OA\Parameter(
        name: 'principal_id',
        in: 'query',
        required: false,
        description: 'Repeatable principal id to scope the listing by. Intersected with the caller\'s visible principals server-side; out-of-scope ids are silently dropped. Used by the Media Archive plugin\'s `ALL / My Media / Group X` chip row. Use the `principal_id[]=…` array-bracket form so repeated keys are preserved through PHP parse_str.',
        schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer')),
    )]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        $query = ListMediaQueryBuilder::fromRequest($request, $userId);
        // `?principal_id=` is the dashboard-style scope filter. We have
        // to intersect the request values with the caller's visible
        // principals here so the service never sees a principal the
        // caller can't act as. An out-of-scope id is silently dropped;
        // an empty intersection leaves the principalIds null so the
        // service falls back to the legacy ownership union.
        $query = $this->applyPrincipalScope($query, $userId);
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

    /**
     * Narrow the query's `principalIds` to the caller's visible
     * principals — mirrors {@see AgentController::resolvePrincipalFilter()}
     * so an out-of-scope id can never reach the SQL builder. The input is
     * dropped silently (typo tolerance) rather than 400-ed; an authenticated
     * listing endpoint must not surface filter-syntax errors.
     *
     * Returns a fresh DTO rather than mutating in place because
     * {@see ListMediaQuery} is `readonly`.
     */
    private function applyPrincipalScope(ListMediaQuery $query, ?int $userId): ListMediaQuery
    {
        $raw = $query->principalIds;
        if ($raw === null || $raw === []) {
            return $query;
        }
        // No caller, or no visible principals → clear the filter so the
        // service falls back to the legacy `agentOwnerUserId` ownership
        // union (which is itself null when unauthenticated, so the listing
        // ends up empty — the safe default).
        $visible = $userId !== null ? $this->principalResolver->visiblePrincipalIds($userId) : [];
        if ($visible === []) {
            return $this->withPrincipalIds($query, null);
        }
        $intersection = array_values(array_intersect($raw, $visible));
        return $this->withPrincipalIds($query, $intersection === [] ? null : $intersection);
    }

    /**
     * Tiny DTO rebuilder so {@see applyPrincipalScope()} stays under the
     * 3-return brain-overload ceiling. The other fields are passed
     * through verbatim — the controller only ever replaces principalIds.
     */
    private function withPrincipalIds(ListMediaQuery $query, ?array $principalIds): ListMediaQuery
    {
        return new ListMediaQuery(
            mediaType: $query->mediaType,
            mediaTypes: $query->mediaTypes,
            agentId: $query->agentId,
            userId: $query->userId,
            pluginSlug: $query->pluginSlug,
            toolName: $query->toolName,
            from: $query->from,
            to: $query->to,
            search: $query->search,
            sort: $query->sort,
            uploadSource: $query->uploadSource,
            ownership: $query->ownership,
            agentOwnerUserId: $query->agentOwnerUserId,
            principalIds: $principalIds,
            page: $query->page,
            perPage: $query->perPage,
        );
    }
}
