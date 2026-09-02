<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use DateTime;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

/**
 * Single entry point for listing, finding, deleting, and ingest-facading
 * archived media.
 *
 * Idempotent on `(tool_call_id, source_url)` so a retry of the same tool
 * call returns the same row instead of duplicating. URL fetch failures
 * downgrade to `external` mode (original URL preserved) so the row
 * survives a CDN outage; byte-mode failures are fatal — the operator
 * asked us to keep the bytes.
 *
 * The ingest pipeline lives in {@see MediaArchiveIngestPipeline} so this
 * class stays under the Sonar 20-method threshold. The URL branch lives
 * in {@see MediaArchiveUrlResolver} for the same reason.
 */
final class MediaArchiveService
{
    /** Prefix used by every persisted `asset_url`. */
    public const OPAQUE_ASSET_URL_PREFIX = '/api/v1/assets/';

    /**
     * Hard ceiling for `storage_mode = data_url` writes — the bytes
     * land in `media_assets.payload` (MEDIUMBLOB on MySQL/MariaDB after
     * migration 0064; SQLite has no intrinsic cap, this 16 MiB applies
     * as a sanity-bound — `data:image/png;base64,…` balloons the chat
     * bubble HTML).
     */
    public const DATA_URL_MAX_BYTES = 16 * 1024 * 1024;

    /**
     * 64 hex chars = 256 bits of entropy — unguessable even for a row
     * referenced by an attacker-known UUID.
     */
    public static function mintPublicAccessToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Returns null for unknown mimes — caller omits the extension
     * entirely rather than fabricating a guess that could mislead browsers.
     */
    public static function extensionForMime(?string $mime): ?string
    {
        if (!is_string($mime) || $mime === '') {
            return null;
        }
        $map = [
            'audio/mpeg'       => 'mp3',
            'audio/mp3'        => 'mp3',
            'audio/wav'        => 'wav',
            'audio/x-wav'      => 'wav',
            'audio/ogg'        => 'ogg',
            'audio/mp4'        => 'm4a',
            'audio/x-m4a'      => 'm4a',
            'audio/flac'       => 'flac',
            'video/mp4'        => 'mp4',
            'video/webm'       => 'webm',
            'video/quicktime'  => 'mov',
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/gif'        => 'gif',
            'image/webp'       => 'webp',
            'image/svg+xml'    => 'svg',
            'application/pdf'  => 'pdf',
            'text/plain'       => 'txt',
        ];
        return $map[strtolower($mime)] ?? null;
    }

    /**
     * Reverse of {@see extensionForMime()}: maps a file extension to its
     * canonical MIME type. Returns null when the extension is not in the
     * static map. Used by `MediaAllowedTypesService` to convert configured
     * image extensions into the MIME list the frontend picker renders.
     */
    public static function mimeForExtension(?string $ext): ?string
    {
        if (!is_string($ext) || $ext === '') {
            return null;
        }
        $reverse = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
        ];
        return $reverse[strtolower(ltrim($ext, '.'))] ?? null;
    }

    private readonly PrincipalService $principalService;

    private readonly PrincipalResolver $principalResolver;

    public function __construct(
        private readonly MediaArchiveIngestPipeline $ingestPipeline,
        ?PrincipalService $principalService = null,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->principalService = $principalService ?? new PrincipalService(new PrincipalResolver());
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    /**
     * Resolve a list of Media Archive UUIDs to their full rows, in input order,
     * silently dropping any IDs the caller cannot access. Existence-hiding —
     * a foreign id surfaces as a missing slot in the response, never as 404
     * or 403, so the chat list cannot probe for archive rows it does not own.
     *
     * Admin callers bypass the visibility check. Non-admins can read a row
     * when either (a) they uploaded it directly (`asset.user_id === userId`)
     * or (b) the agent that produced it lives in one of their visible
     * principals (`PrincipalResolver::isVisibleTo`) — same union as the
     * `GET /api/v1/assets/{filename}` controller, so an attachment URL
     * the chat list renders will always resolve through `AssetController`.
     *
     * The cap (64 IDs) is enforced by the controller; this method itself
     * does not enforce the cap so it stays composable for in-process callers.
     *
     * @param  list<string> $ids
     * @return list<MediaAsset>
     */
    public function resolveMany(array $ids, int $userId, bool $isAdmin): array
    {
        if ($ids === []) {
            return [];
        }
        $assets = MediaAsset::query()->whereIn('id', $ids)->get();
        $byId = [];
        foreach ($assets as $asset) {
            $byId[$asset->id] = $asset;
        }
        $resolved = [];
        foreach ($ids as $id) {
            $asset = $byId[$id] ?? null;
            if ($asset === null) {
                continue;
            }
            if ($this->canResolveAsset($asset, $userId, $isAdmin)) {
                $resolved[] = $asset;
            }
        }

        return $resolved;
    }

    /**
     * Visibility check for {@see resolveMany()}. Mirrors
     * {@see \Spora\Http\AssetController::ownsAsset()} so the asset URL
     * the chat list renders always resolves when the row is here.
     */
    private function canResolveAsset(MediaAsset $asset, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ($asset->user_id !== null && (int) $asset->user_id === $userId) {
            return true;
        }
        if ($asset->agent_id === null) {
            return false;
        }

        return $this->principalResolver->isVisibleTo((int) $asset->agent_id, $userId);
    }

    /**
     * Ingest a single asset. Returns the persisted row (existing row
     * returned unchanged when a row with the same `tool_call_id` and
     * `source_url` already exists).
     *
     * @throws InvalidArgumentException When `hex`/`base64` decoding fails.
     */
    public function ingest(MediaIngestRequest $request): MediaAsset
    {
        return $this->ingestPipeline->ingest($request);
    }

    public function list(ListMediaQuery $query): LengthAwarePaginator
    {
        /** @var Builder<MediaAsset> $builder */
        $builder = MediaAsset::query();

        // Derivatives are full `media_assets` rows (linked to their parent
        // via the `media_derivatives` join table). Without this filter they
        // show up as siblings of the original in the library grid — a
        // thumbnail next to the source it was derived from. The
        // `media_derivatives_derivative_id_idx` index keeps the subquery
        // cheap. Reach a derivative through its parent's detail page →
        // `VersionsStrip` instead.
        $builder->whereNotIn('id', Capsule::table('media_derivatives')->select('derivative_id'));

        if ($query->mediaTypes !== null && $query->mediaTypes !== []) {
            $builder->whereIn(
                'media_type',
                array_map(static fn(MediaType $t): string => $t->value, $query->mediaTypes),
            );
        } elseif ($query->mediaType !== null) {
            $builder->where('media_type', $query->mediaType->value);
        }
        if ($query->agentId !== null) {
            $builder->where('agent_id', $query->agentId);
        }
        $this->applyOwnerScope($builder, $query);
        if ($query->pluginSlug !== null) {
            $builder->where('plugin_slug', $query->pluginSlug);
        }
        if ($query->toolName !== null) {
            $builder->where('tool_name', $query->toolName);
        }
        if ($query->uploadSource !== null) {
            // Restricted to the upload-source column on the migration's
            // `media_assets_upload_source_created_at_idx` index — see
            // migration 0056. Filter is a single equality, so the planner
            // uses the leading column of the index.
            $builder->where('upload_source', $query->uploadSource);
        }
        if ($query->from !== null) {
            $builder->where('created_at', '>=', Carbon::instance(DateTime::createFromInterface($query->from)));
        }
        if ($query->to !== null) {
            $builder->where('created_at', '<=', Carbon::instance(DateTime::createFromInterface($query->to)));
        }
        if ($query->search !== null && trim($query->search) !== '') {
            $term = '%' . trim($query->search) . '%';
            // Escape LIKE wildcards so user-typed terms do not act as SQL
            // patterns; the substring match itself stays functional.
            $escaped = addcslashes(trim($query->search), '%_\\');
            $prefixed = '%' . $escaped . '%';
            $builder->where(function (Builder $q) use ($term, $prefixed): void {
                $q->where('prompt', 'like', $term)
                    ->orWhere('filename', 'like', $term)
                    ->orWhere('asset_url', 'like', $term)
                    ->orWhere('source_url', 'like', $term)
                    // Substring UUID match. `id` is a 36-char UUID column;
                    // a full UUID gives an exact match, a prefix/suffix
                    // gives a partial LIKE match. The leading-wildcard
                    // query is fine for a 36-char keyspace and avoids
                    // a separate exact-match fast path.
                    ->orWhere('id', 'like', $prefixed);
            });
        }

        $sort = in_array($query->sort, ListMediaQuery::ALLOWED_SORTS, true)
            ? $query->sort
            : ListMediaQuery::SORT_CREATED_DESC;
        match ($sort) {
            ListMediaQuery::SORT_CREATED_ASC => $builder->orderBy('created_at', 'asc'),
            ListMediaQuery::SORT_SIZE_DESC => $builder->orderBy('byte_size', 'desc'),
            default => $builder->orderBy('created_at', 'desc'),
        };

        return $builder->paginate(
            perPage: $query->perPage(),
            page: $query->page(),
        );
    }

    public function find(string $id): ?MediaAsset
    {
        return MediaAsset::query()->find($id);
    }

    public function delete(string $id): void
    {
        $asset = MediaAsset::query()->find($id);
        if ($asset === null) {
            return;
        }
        $asset->delete();
    }

    public function countForAgent(int $agentId): int
    {
        return MediaAsset::query()->where('agent_id', $agentId)->count();
    }

    /**
     * True iff the asset belongs to the given principal: either a direct
     * upload by the principal's owner user, or attached to any agent owned
     * by that principal. Used by `MediaTool::assetInScope()` for the
     * single-asset auth check (get_media / get_public_url / get_embed_code);
     * mirrors the LIST endpoint's `applyPrincipalIdScope()` query so both
     * paths share one principal-aware definition.
     *
     * Returns `false` when the principal context is unresolvable
     * (cold principal / boot-auth test harness with no PrincipalContext).
     */
    public function isAssetInPrincipalScope(
        MediaAsset $asset,
        ?PrincipalContext $context,
        ?int $legacyUserId,
    ): bool {
        $principalId = $context !== null ? $context->principalId : 0;
        $ownerUserId = $context !== null ? $context->ownerUserId : $legacyUserId;

        if ($principalId <= 0 || $ownerUserId === null) {
            return false;
        }
        return ($asset->user_id !== null && (int) $asset->user_id === $ownerUserId)
            || (
                $asset->agent_id !== null
                && Agent::query()
                    ->where('id', (int) $asset->agent_id)
                    ->where('principal_id', $principalId)
                    ->exists()
            );
    }

    public function writePayloadToAsset(MediaAsset $asset, string $bytes): void
    {
        $this->ingestPipeline->writePayloadToAsset($asset, $bytes);
    }

    /**
     * Best-effort converter invocation. A throw is logged and swallowed
     * so a corrupt PDF or unsupported variant doesn't fail the upload.
     * Skipped when markdown_content is already populated to keep re-ingest
     * idempotent. Delegates to {@see MediaArchiveIngestPipeline}.
     */
    public function runConversionPipeline(MediaAsset $asset, string $bytes): void
    {
        $this->ingestPipeline->runConversionPipeline($asset, $bytes);
    }

    /**
     * Dispatch the ownership filter — three modes in priority order:
     *   1. principalIds (dashboard-style ALL / Mine / Group A / ...):
     *      media attached to agents in any of the listed principals,
     *      plus direct uploads by the caller but only when the caller's
     *      user-principal is included. The controller has already
     *      intersected the list with `visiblePrincipalIds`, so the
     *      service trusts every value.
     *   2. agentOwnerUserId (legacy `?ownership=mine`): uploads by the
     *      caller OR media attached to agents owned by the caller's
     *      user-principal. Kept for back-compat with older plugin
     *      versions that don't send `?principal_id=`.
     *   3. userId (direct callers / tests): plain `WHERE user_id = N`.
     *
     * Extracted from {@see list()} to keep the dispatch under the
     * S3776 cognitive-complexity budget; each branch has its own helper.
     */
    private function applyOwnerScope(Builder $builder, ListMediaQuery $query): void
    {
        if ($query->principalIds !== null && $query->principalIds !== []) {
            $this->applyPrincipalIdScope($builder, $query);
            return;
        }
        if ($query->agentOwnerUserId !== null) {
            $this->applyLegacyOwnershipScope($builder, $query);
            return;
        }
        if ($query->userId !== null) {
            $builder->where('user_id', $query->userId);
        }
    }

    /**
     * Apply the dashboard-style principal scope: media attached to any
     * agent whose principal is in the list. Direct uploads by the caller
     * (rows with `user_id = callerId`) are NOT auto-included here — a
     * direct upload attributed to a group-principal via the plugin upload
     * dialog belongs to that group, not the caller's user-principal. The
     * plugin's `My Media` chip is therefore a strict `principal_id = …`
     * filter, matching the user's mental model of "media of my principal".
     *
     * Extracted from {@see list()} to drop the S3776 cognitive-complexity
     * budget; the controller has already vetted every id against
     * `visiblePrincipalIds()` so the service trusts the list as-is.
     *
     * Post-migration 0075, the bulk of rows hit the indexed
     * `media_assets.principal_id IN (…)` fast path. The `orWhereIn`
     * through `agents` is a back-compat fallback for legacy rows whose
     * `principal_id` stayed NULL (uploaded before 0075 ran) so the
     * same caller still surfaces them under their principal via the
     * `agent_id → agents.principal_id` join.
     */
    private function applyPrincipalIdScope(Builder $builder, ListMediaQuery $query): void
    {
        $principalIds = array_values(array_unique(array_map('intval', $query->principalIds ?? [])));
        $builder->where(function (Builder $q) use ($principalIds): void {
            // Fast path: indexed principal_id column on media_assets.
            $q->whereIn('principal_id', $principalIds);
            // Back-compat: legacy rows where principal_id was left NULL
            // still surface under the same principal via the agent join.
            // The outer `whereNull('media_assets.principal_id')` keeps
            // the planner on the agents subquery (idx_agents_principal_id)
            // and avoids double-counting rows already in the fast path.
            $q->orWhere(function (Builder $sub) use ($principalIds): void {
                $sub->whereNull('media_assets.principal_id')
                    ->whereIn('media_assets.agent_id', Agent::query()
                        ->select('id')
                        ->whereIn('principal_id', $principalIds));
            });
        });
    }

    /**
     * Legacy `?ownership=mine` ownership union: media uploaded by the
     * caller OR media attached to any agent owned by the caller's
     * user-principal. Materialises the user-principal because migration
     * 0067 moved ownership from `agents.user_id` to `agents.principal_id`.
     *
     * Kept for back-compat with older plugin versions that don't send
     * `?principal_id=`. Newer plugin versions always hit
     * {@see applyPrincipalIdScope()}.
     */
    private function applyLegacyOwnershipScope(Builder $builder, ListMediaQuery $query): void
    {
        $userId = (int) $query->agentOwnerUserId;
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        $builder->where(function (Builder $q) use ($userId, $principalId): void {
            $q->where('user_id', $userId)
              ->orWhereIn('agent_id', Agent::query()
                  ->select('id')
                  ->where('principal_id', $principalId));
        });
    }
}
