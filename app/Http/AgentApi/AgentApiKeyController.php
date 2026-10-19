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
 * A caller needs the manage role on the agent's principal, which is
 * *narrower* than editing the agent: `PATCH /agents/{id}` is gated on
 * visibility, so a plain group member can edit the agent but may not mint a
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
        $body = $this->safeDecodeJson($request);
        $name = $body instanceof JsonResponse ? $body : $this->checkedName($body);

        if ($name instanceof JsonResponse) {
            return $name;
        }

        [$key, $plaintext] = $this->issuer->issue($agentId, $name, $this->authService->currentUserId());

        return new JsonResponse(
            ['data' => ['key' => $this->present($key), 'secret' => $plaintext]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return JsonResponse|string the trimmed name, or the 422 envelope
     */
    private function checkedName(array $body): JsonResponse|string
    {
        $name = $body['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'name is required.');
        }

        $name = trim($name);

        return mb_strlen($name) > self::MAX_NAME_LENGTH
            ? $this->unprocessable('VALIDATION_ERROR', 'name must be 100 characters or fewer.')
            : $name;
    }

    #[OA\Delete(
        path: '/api/v1/agents/{agentId}/api-keys/{keyId}',
        summary: 'Revoke an API key',
        tags: ['Agents'],
    )]
    #[OA\Response(response: 200, description: 'Revoked. Idempotent — the same body whether or not the key existed.')]
    public function destroy(Request $request): JsonResponse
    {
        $denied = $this->guard($request);

        if ($denied !== null) {
            return $denied;
        }

        $agentId = (int) $request->attributes->get('agentId', 0);
        $keyId = (string) $request->attributes->get('keyId', '');

        // Scoped by agent, so a key id cannot be used to revoke another agent's
        // key. The response does not say whether the row existed, which is what
        // stops this being a probe for which key ids are real.
        //
        // 200 with a body rather than 204: a 204 goes out with no body and no
        // Content-Length, and Vite's dev proxy turns exactly that into
        // `502 No Content` on the way through. A normal bodied response also
        // gives the caller something to log.
        AgentApiKey::where('agent_id', $agentId)->where('id', $keyId)->delete();

        return new JsonResponse([
            'data' => [
                'revoked' => true,
                'key_id' => $keyId,
            ],
        ]);
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
     * A caller who can see an agent but not manage it is told 403, where
     * editing the agent would have succeeded — the agent itself is not
     * hidden from them.
     */
    private function guard(Request $request): ?JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($userId === null) {
            return $this->unauthenticated();
        }

        $agent = Agent::find((int) $request->attributes->get('agentId', 0));

        return match (true) {
            $agent === null => $this->notFound('AGENT_NOT_FOUND', 'Agent not found.'),
            !$this->canManage($agent, (int) $userId)
                => $this->forbidden('FORBIDDEN', 'You cannot manage API keys for this agent.'),
            default => null,
        };
    }

    private function canManage(Agent $agent, int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }

        return $this->principalResolver->canManagePrincipal((int) $agent->principal_id, $userId);
    }
}
