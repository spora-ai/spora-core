<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Services\AgentServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reasoning-effort levels an agent can use, and the one it defaults to.
 *
 * Read-only, and the operator-facing half of the effort endpoints: the
 * agent, group and global surfaces report the same thing about an agent
 * through the same projection, so the four answers cannot drift.
 */
final class AgentEffortController
{
    private const ERR_AGENT_NOT_FOUND = 'Agent not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly EffortProjection $projection,
    ) {}

    #[OA\Get(
        path: '/api/v1/agents/{id}/effort',
        tags: ['Agents'],
        summary: 'The reasoning-effort levels this agent can use',
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON envelope: `{data: {supported: bool, levels: [{value, label}], default: string|null}}`.',
            ),
            new OA\Response(response: 404, description: 'NOT_FOUND — no such agent, or not one the caller may see.'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $agent = $this->agentService->getAgent($agentId, $userId);

        if ($agent === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_AGENT_NOT_FOUND]],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->projection->respond($agent);
    }
}
