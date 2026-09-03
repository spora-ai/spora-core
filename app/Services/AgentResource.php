<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\AgentPicture;
use Spora\Models\AgentTool;
use Spora\Models\Principal;

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
     * @param ?AgentResourceContext $context  Optional bundled eager-loads +
     *     resolvers (see {@see AgentResourceContext}). When null, the
     *     resource falls back to lazy resolution + omitted optional fields
     *     — the minimum-viable wire shape used by lightweight callers like
     *     the `?select=id,name` projection.
     *
     * @return array<string, mixed>
     */
    public static function toArray(Agent $agent, ?AgentResourceContext $context = null): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, AgentTool> $tools */
        $tools = ($context !== null ? $context->preloadedTools : null) ?? $agent->agentTools;
        $principal = ($context !== null ? $context->preloadedPrincipal : null) ?? Principal::find((int) $agent->principal_id);

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
            // Per-viewer favourite: read from the context's pre-loaded pivot
            // set rather than the legacy shared column (which Plan A
            // removed in migration 0079). Null context → default false.
            'is_favorite'          => $context !== null
                && $context->favoritedAgentIds !== null
                && $context->favoritedAgentIds->has((int) $agent->id),
            // Ownership: every agent has exactly one owning principal
            // (user or group) since migration 0067. The dashboard filter
            // chips, the agent sidebar grouping, and the agent-card owner
            // badge all consume this; the resolved `principal` block keeps
            // the frontend from doing its own (and probably stale) lookup.
            // The DB schema is NOT NULL today; the null branch is
            // defensive for legacy fixtures or future soft-delete columns.
            'principal_id'         => $agent->principal_id,
            'principal'            => self::principalBlock($principal),
            'created_at'           => $agent->created_at !== null
                ? $agent->created_at->format(DateTimeInterface::ATOM)
                : null,
            'tools'                => $tools->map(static function (AgentTool $t) use ($context): array {
                $entry = [
                    'tool_class' => $t->tool_class,
                    'tool_name'  => $t->tool_name,
                ];
                // Per-tool icon resolved server-side via the 3-layer chain.
                // null on the wire = frontend's <Icon> falls back to 'puzzle'.
                if ($context?->iconResolver !== null) {
                    $entry['icon'] = $context->iconResolver->resolve($t->tool_class);
                }
                return $entry;
            })->values()->toArray(),
        ];

        if ($context !== null && $context->supportsImageInput !== null) {
            $payload['llm_supports_image_input'] = $context->supportsImageInput;
        }

        if ($context !== null && $context->pictureService !== null) {
            $payload['profile_picture'] = $context->preloadedPicture instanceof AgentPicture
                ? $context->pictureService->pictureToWireWithAsset($context->preloadedPicture, $context->preloadedMediaAsset)
                : $context->pictureService->toWireShape((int) $agent->id);
        }

        return $payload;
    }

    /**
     * Resolved principal block matching the shape consumed by the
     * frontend's `Principal` interface (`id`, `type`, `name`, `user_id`,
     * `group_id`). Returns null when the agent has no owning principal
     * — the dashboard treats null as "owner unknown" rather than the
     * caller's user-principal.
     *
     * @return array{id:int,type:string,name:string,user_id:?int,group_id:?int}|null
     */
    private static function principalBlock(?Principal $principal): ?array
    {
        if ($principal === null) {
            return null;
        }
        $name = $principal->type === Principal::TYPE_USER
            ? self::userPrincipalName($principal)
            : self::groupPrincipalName($principal);
        return [
            'id'       => (int) $principal->id,
            'type'     => $principal->type,
            'name'     => $name,
            'user_id'  => $principal->user_id !== null ? (int) $principal->user_id : null,
            'group_id' => $principal->group_id !== null ? (int) $principal->group_id : null,
        ];
    }

    private static function userPrincipalName(Principal $principal): string
    {
        $row = Capsule::table('users')
            ->where('id', $principal->user_id)
            ->select(['email', 'name'])
            ->first();
        if ($row === null) {
            return 'User #' . $principal->user_id;
        }
        $display = $row->name ?? $row->email;
        return is_string($display) && $display !== '' ? $display : (string) $row->email;
    }

    private static function groupPrincipalName(Principal $principal): string
    {
        $row = Capsule::table('groups')
            ->where('id', $principal->group_id)
            ->select(['name'])
            ->first();
        return $row !== null && isset($row->name) && $row->name !== ''
            ? (string) $row->name
            : 'Group #' . $principal->group_id;
    }
}
