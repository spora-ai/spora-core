<?php

declare(strict_types=1);

namespace Spora\Http\AgentApi;

use OpenApi\Attributes as OA;
use Spora\Auth\AgentApiKeyIssuer;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\Agent;
use Spora\Models\AgentApiKey;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minting and revoking an agent's API keys, from the admin UI.
 *
 * Session-authenticated and CSRF-protected, unlike the Agent API itself: this
 * is the surface an operator uses, and it lives behind the same
 * authentication as the rest of the admin.
 *
 * The plaintext key is returned exactly once, by `store`. There is no
 * endpoint that can return it again, and the row stores only a SHA-256
 * digest, so a lost key is replaced rather than recovered.
 *
 * A caller needs the manage role on the agent's principal — the same gate as
 * editing the agent itself. Anyone who can see the agent may not mint a
 * credential that drives it.
 */
#[OA\Tag(name: 'Agents', description: 'Agent administration')]
final class AgentApiKeyController
{
    use JsonControllerHelpers;

    private const MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly AuthService $authService,
        private readonly PrincipalResolver $principalResolver,
        private readonly AgentApiKeyIssuer $issuer,
    ) {}

    #[OA\Get(
        path: '/api/v1/agents/{agentId}/api-keys',
        summary: "List an agent's API keys",
        tags: ['Agents'],
    )]
    #[OA\Response(response: 200, description: 'Key metadata. Secrets are never returned.')]
    #[OA\Response(response: 403, description: 'The caller cannot manage this agent.')]
    public function index(Request $request): JsonResponse
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $agentId = (int) $request->attributes->get('agentId', 0);
        $keys = AgentApiKey::where('agent_id', $agentId)->orderByDesc('created_at')->get();

        return new JsonResponse([
            'data' => [
                'keys' => $keys->map(fn(AgentApiKey $key): array => $this->present($key))->all(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/agents/{agentId}/api-keys',
        summary: 'Mint an API key',
        tags: ['Agents'],
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name'],
        properties: [new OA\Property(property: 'name', type: 'string', maxLength: 100)],
    ))]
    #[OA\Response(response: 201, description: 'The key, with its plaintext secret. Shown once.')]
    #[OA\Response(response: 422, description: 'The name was missing or too long.')]
    public function store(Request $request): JsonResponse
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $agentId = (int) $request->attributes->get('agentId', 0);
        $body = $this->jsonBody($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'name is required.');
        }

        $name = trim($name);

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return $this->unprocessable('VALIDATION_ERROR', 'name must be 100 characters or fewer.');
        }

        [$key, $plaintext] = $this->issuer->issue($agentId, $name, $this->authService->currentUserId());

        return new JsonResponse(
            ['data' => ['key' => $this->present($key), 'secret' => $plaintext]],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Delete(
        path: '/api/v1/agents/{agentId}/api-keys/{keyId}',
        summary: 'Revoke an API key',
        tags: ['Agents'],
    )]
    #[OA\Response(response: 204, description: 'Revoked. Idempotent.')]
    public function destroy(Request $request): JsonResponse
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $agentId = (int) $request->attributes->get('agentId', 0);
        $keyId = (string) $request->attributes->get('keyId', '');

        // Scoped by agent, so a key id cannot be used to revoke another agent's
        // key. Answering 204 whether or not the row existed is what stops
        // that from being a probe for which key ids are real.
        AgentApiKey::where('agent_id', $agentId)->where('id', $keyId)->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{id: string, agent_id: int, name: string, last_used_at: ?string, created_at: ?string}
     */
    private function present(AgentApiKey $key): array
    {
        return [
            'id' => $key->id,
            'agent_id' => (int) $key->agent_id,
            'name' => $key->name,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }

    /**
     * Authenticate, then require the manage role on the agent's principal.
     *
     * A caller who can see an agent but not manage it is told 403, which is
     * the same answer editing the agent would give — the agent itself is not
     * hidden from them.
     */
    private function guard(Request $request): ?JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($userId === null) {
            return $this->unauthenticated();
        }

        $agentId = (int) $request->attributes->get('agentId', 0);
        $agent = Agent::find($agentId);

        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', 'Agent not found.');
        }

        if (!$this->canManage($agent, (int) $userId)) {
            return $this->forbidden('FORBIDDEN', 'You cannot manage API keys for this agent.');
        }

        return null;
    }

    private function canManage(Agent $agent, int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }

        return $this->principalResolver->canManagePrincipal((int) $agent->principal_id, $userId);
    }
}
