<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Spora\Models\Agent;
use Spora\Models\AgentPicture;
use Spora\Models\AgentTool;
use Spora\Models\MediaAsset;
use Spora\Services\AgentPictures\AgentPictureService;

/**
 * Agent → wire-format array mapping. Single source of truth for the shape
 * of an AgentResource as emitted by GET /api/v1/agents and the
 * AgentController's create/update/show responses.
 *
 * Lives outside the service so the mapping doesn't depend on AgentService
 * and so Service-level and Controller-level responses stay byte-identical.
 */
final class AgentResource
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
     *
     * @return array<string, mixed>
     */
    public static function toArray(
        Agent $agent,
        ?bool $supportsImageInput = null,
        ?ToolIconResolver $iconResolver = null,
        ?AgentPictureService $pictureService = null,
        ?Collection $preloadedTools = null,
        ?AgentPicture $preloadedPicture = null,
        ?MediaAsset $preloadedMediaAsset = null,
    ): array {
        /** @var Collection<int, AgentTool> $tools */
        $tools = $preloadedTools ?? $agent->agentTools;

        $payload = [
            'id'                   => (int) $agent->id,
            'name'                 => $agent->name,
            'description'          => $agent->description,
            'system_prompt'        => $agent->system_prompt,
            'notes'                => $agent->notes,
            'llm_driver_config_id' => $agent->llm_driver_config_id,
            'max_steps'            => (int) $agent->max_steps,
            'is_active'            => (bool) $agent->is_active,
            'allow_followup'       => (bool) $agent->allow_followup,
            'retry_after_minutes'  => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'          => (int) ($agent->max_retries ?? 0),
            'is_pinned'            => (bool) ($agent->is_pinned ?? false),
            'is_archived'          => (bool) ($agent->is_archived ?? false),
            'is_favorite'          => (bool) ($agent->is_favorite ?? false),
            'created_at'           => $agent->created_at !== null
                ? $agent->created_at->format(DateTimeInterface::ATOM)
                : null,
            'tools'                => $tools->map(static function (AgentTool $t) use ($iconResolver): array {
                $entry = [
                    'tool_class' => $t->tool_class,
                    'tool_name'  => $t->tool_name,
                ];
                // Per-tool icon resolved server-side via the 3-layer chain.
                // null on the wire = frontend's <Icon> falls back to 'puzzle'.
                if ($iconResolver !== null) {
                    $entry['icon'] = $iconResolver->resolve($t->tool_class);
                }
                return $entry;
            })->values()->toArray(),
        ];

        if ($supportsImageInput !== null) {
            $payload['llm_supports_image_input'] = $supportsImageInput;
        }

        if ($pictureService !== null) {
            $payload['profile_picture'] = $preloadedPicture instanceof AgentPicture
                ? $pictureService->pictureToWireWithAsset($preloadedPicture, $preloadedMediaAsset)
                : $pictureService->toWireShape((int) $agent->id);
        }

        return $payload;
    }
}
