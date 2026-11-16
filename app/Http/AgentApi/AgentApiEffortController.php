<?php

declare(strict_types=1);

namespace Spora\Http\AgentApi;

use OpenApi\Attributes as OA;
use Spora\Http\EffortProjection;
use Spora\Services\ApiKeys\ApiKeyContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The reasoning-effort levels this agent's LLM configuration offers.
 *
 * The key pins one agent, so the path carries no id: a client holding a key
 * asks what its own agent can do, and gets the answer the admin endpoint
 * gives for that same agent.
 */
#[OA\Tag(name: 'Agent-api', description: 'Per-agent API for external tools')]
final class AgentApiEffortController
{
    public function __construct(
        private readonly EffortProjection $projection,
    ) {}

    #[OA\Get(
        path: '/api/v1/agent-api/effort',
        tags: ['Agent-api'],
        summary: "The reasoning-effort levels this agent's LLM configuration offers",
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON envelope: `{data: {supported: bool, levels: [{value, label}], default: string|null}}`.',
            ),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);

        return $this->projection->respond($context->requireAgent());
    }
}
