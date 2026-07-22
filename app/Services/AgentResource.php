<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeInterface;
use Spora\Models\Agent;
use Spora\Models\AgentTool;

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
     *
     * @return array<string, mixed>
     */
    public static function toArray(
        Agent $agent,
        ?bool $supportsImageInput = null,
        ?ToolIconResolver $iconResolver = null,
    ): array {
        /** @var \Illuminate\Database\Eloquent\Collection<int, AgentTool> $tools */
        $tools = $agent->agentTools;

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

        return $payload;
    }
}
