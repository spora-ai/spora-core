<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Spora\Models\AgentPicture;
use Spora\Models\AgentTool;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\AgentPictures\AgentPictureService;

/**
 * Optional eagerly-loaded relations + resolvers for {@see AgentResource::toArray()}.
 *
 * Bundles the eight optional dependencies the resource accepts so the
 * signature stays under the S107 7-parameter cap (the per-viewer
 * favourite set is the 8th field — Plan A). Each field maps 1:1 to a
 * previously-positional `AgentResource::toArray()` argument; see the
 * constructor phpdoc for the per-field contract.
 */
final class AgentResourceContext
{
    /**
     * @param bool|null $supportsImageInput  Whether the agent's configured LLM
     *     accepts image blocks. `null` means the caller could not resolve the
     *     driver (no factory injected, agent has no `llm_driver_config_id`,
     *     or driver construction threw); the field is then omitted from the
     *     response rather than reported as `false` to avoid misleading the
     *     frontend. Pass a real bool from AgentController where the
     *     DriverFactory is available.
     * @param ?ToolIconResolver $iconResolver  Resolver for the per-tool icon
     *     via the 3-layer chain (tool.icon → plugin.icon → null). Optional —
     *     when null, the per-tool `icon` field is omitted from each tool
     *     entry (callers without DI access can pass null and the wire payload
     *     still parses; the frontend's <Icon> component falls back to
     *     'puzzle' on missing keys).
     * @param ?AgentPictureService $pictureService  When provided, the
     *     `profile_picture` field is included in the wire payload (the
     *     resolved `archetype`, `variant_key`, `palette_key`, plus the
     *     derived `fg_color`/`bg_color`, or the uploaded image URL).
     *     When null — e.g. from the `?select=id,name` projection — the
     *     field is omitted. The dashboard / sidebar / agent-detail render
     *     sites always pass a service.
     * @param ?Collection<int, AgentTool> $preloadedTools  Pre-loaded agentTools
     *     relation (typically passed by AgentService::getAgentsForUser which
     *     eager-loads `agentTools` to avoid N+1 on the dashboard endpoint).
     *     When null, AgentResource reads `$agent->agentTools` lazily.
     * @param ?AgentPicture $preloadedPicture  Pre-loaded profilePicture
     *     relation. When null, the resource falls back to
     *     AgentPictureService::toWireShape() which performs its own lookup.
     * @param ?MediaAsset $preloadedMediaAsset  Pre-loaded mediaAsset for an
     *     uploaded picture. Ignored when `$preloadedPicture` is null or has
     *     no `media_asset_id`. Pass alongside `$preloadedPicture` to fully
     *     avoid N+1 on the dashboard endpoint.
     * @param ?Principal $preloadedPrincipal  Pre-loaded `principal` relation
     *     so the dashboard listing doesn't issue one Principal::find per
     *     agent (then another user/group lookup for the display name).
     *     When null, the resource resolves the principal lazily.
     * @param ?SupportCollection<int, int> $favoritedAgentIds  Cached
     *     set of agent ids the caller has favourited — populated by the
     *     dashboard listing (AgentService::getAgentsForUser) via a single
     *     `SELECT agent_id FROM user_agent_favorites WHERE user_id = ? AND
     *     agent_id IN (...)`. AgentResource's `is_favorite` field reads from
     *     this set so the favourite toggle is per-caller. When null, the
     *     field defaults to false (callers that don't care about favourites
     *     — e.g. the `?select=id,name` projection — can skip the pivot
     *     lookup entirely).
     */
    public function __construct(
        public readonly ?bool $supportsImageInput = null,
        public readonly ?ToolIconResolver $iconResolver = null,
        public readonly ?AgentPictureService $pictureService = null,
        public readonly ?Collection $preloadedTools = null,
        public readonly ?AgentPicture $preloadedPicture = null,
        public readonly ?MediaAsset $preloadedMediaAsset = null,
        public readonly ?Principal $preloadedPrincipal = null,
        public readonly ?SupportCollection $favoritedAgentIds = null,
    ) {}
}
