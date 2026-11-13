<?php

declare(strict_types=1);

namespace Spora\Http;

/**
 * The one description of the `effort` request field.
 *
 * The paragraph names the chain that decides the level and the endpoints
 * that list the levels, so it changes whenever either does — and a copy
 * per surface drifts silently, with nothing to say so.
 */
final class EffortOpenApi
{
    public const FIELD_DESCRIPTION = 'The reasoning effort for this message. Omit it and the agent\'s default applies, then the LLM configuration\'s default, then the model default; an empty string asks for the model default. A level the agent\'s LLM configuration does not offer is refused with 422 EFFORT_LEVEL_UNSUPPORTED. The levels are listed by GET /api/v1/agents/{id}/effort, GET /api/v1/agent-api/effort, GET /api/v1/group-api/agents/{agentId}/effort and GET /api/v1/global-api/agents/{agentId}/effort.';
}
