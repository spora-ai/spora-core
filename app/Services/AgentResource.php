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
     * @return array<string, mixed>
     */
    public static function toArray(Agent $agent): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, AgentTool> $tools */
        $tools = $agent->agentTools;

        return [
            'id'                   => (int) $agent->id,
            'name'                 => $agent->name,
            'description'          => $agent->description,
            'system_prompt'        => $agent->system_prompt,
            'llm_driver_config_id' => $agent->llm_driver_config_id,
            'max_steps'            => (int) $agent->max_steps,
            'is_active'            => (bool) $agent->is_active,
            'allow_followup'       => (bool) $agent->allow_followup,
            'retry_after_minutes'  => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'          => (int) ($agent->max_retries ?? 0),
            'is_pinned'            => (bool) ($agent->is_pinned ?? false),
            'is_archived'          => (bool) ($agent->is_archived ?? false),
            'created_at'           => $agent->created_at !== null
                ? $agent->created_at->format(DateTimeInterface::ATOM)
                : null,
            'tools'                => $tools->map(static fn(AgentTool $t): array => [
                'tool_class' => $t->tool_class,
                'tool_name'  => $t->tool_name,
            ])->values()->toArray(),
        ];
    }
}
