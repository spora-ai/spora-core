<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use OpenApi\Attributes as OA;
use Spora\AgentTemplates\ImportResult;
use Spora\Auth\ApiKeyScope;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\Agent;
use Spora\Models\Group;
use Spora\Services\AgentResource;
use Spora\Services\ApiKeys\ApiKeyAgentService;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyReach;
use Spora\Services\ApiKeys\MissingGroupPrincipalException;
use Spora\Services\EffortService;
use Spora\Services\Exceptions\InvalidTemplatePayloadException;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Agent CRUD for the scoped API surfaces, in the Agent Template format.
 *
 * One implementation serves both the group surface and the global surface:
 * the scope comes from the key, and the reach decides which agents exist
 * as far as the caller is concerned. The OpenAPI tag is therefore derived
 * from the path rather than declared, so each surface gets its own page.
 *
 * The payload is the one `POST /api/v1/agent-templates/import` accepts. A
 * create takes it verbatim; an update takes it as a merge — only the
 * fields it names are written, and a tool it does not name is left alone.
 */
final class ScopedApiAgentController
{
    use JsonControllerHelpers;

    private const MSG_NO_SUCH_AGENT = 'No such agent, or not one this key may reach.';

    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    public function __construct(
        private readonly ApiKeyAgentService $agents,
        private readonly ApiKeyReach $reach,
        private readonly ?EffortService $effortService = null,
        private readonly ?PrincipalService $principalService = null,
    ) {}

    #[OA\Get(summary: 'List the agents this key may reach')]
    #[OA\Response(response: 200, description: 'The agents in the key\'s reach.')]
    public function index(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $agents = $this->agents->listAgents($this->reach, $context);

        return new JsonResponse([
            'data' => [
                'agents' => array_map(
                    static fn(Agent $agent): array => AgentResource::toArray($agent),
                    $agents,
                ),
                'total' => count($agents),
            ],
        ]);
    }

    #[OA\Post(summary: 'Create an agent from the import format')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['agent'],
        properties: [
            new OA\Property(property: 'agent', type: 'object', description: 'The agent block of the import format.'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'tools', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'group_id', type: 'integer', description: 'The owning group. The global surface only.'),
        ],
    ))]
    #[OA\Response(response: 201, description: 'The agent was created.')]
    #[OA\Response(response: 404, description: 'The group does not exist, or is outside the key\'s reach.')]
    #[OA\Response(response: 422, description: 'The payload is not a usable import format.')]
    public function store(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $created = $this->createAgent($context, $body);

        if ($created instanceof JsonResponse) {
            return $created;
        }

        return new JsonResponse(
            [
                'data' => [
                    'agent' => AgentResource::toArray($created->agent),
                    'warnings' => $created->warnings,
                    'tools_enabled' => $created->toolsEnabled,
                ],
            ],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Get(summary: 'Read an agent in the import format')]
    #[OA\Response(response: 200, description: 'The agent, as an import-format payload.')]
    #[OA\Response(response: 404, description: self::MSG_NO_SUCH_AGENT)]
    public function show(Request $request, int $agentId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $agent = $this->reach->targetAgent($context, $agentId);

        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', self::MSG_NO_SUCH_AGENT);
        }

        return new JsonResponse(['data' => ['agent' => $this->agents->export($agent)]]);
    }

    #[OA\Patch(summary: 'Update an agent from the import format')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'agent', type: 'object', description: 'The agent fields to write.'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'tools', type: 'array', items: new OA\Items(type: 'object')),
        ],
    ))]
    #[OA\Response(response: 200, description: 'The agent was updated.')]
    #[OA\Response(response: 404, description: self::MSG_NO_SUCH_AGENT)]
    #[OA\Response(response: 422, description: 'The payload is not a usable import format.')]
    public function update(Request $request, int $agentId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $updated = $this->updateAgent($context, $agentId, $body);

        if ($updated instanceof JsonResponse) {
            return $updated;
        }

        return new JsonResponse([
            'data' => [
                'agent' => AgentResource::toArray($updated->agent),
                'warnings' => $updated->warnings,
                'tools_enabled' => $updated->toolsEnabled,
            ],
        ]);
    }

    #[OA\Delete(summary: 'Delete an agent')]
    #[OA\Response(response: 200, description: 'The agent was deleted.')]
    #[OA\Response(response: 404, description: self::MSG_NO_SUCH_AGENT)]
    public function destroy(Request $request, int $agentId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $agent = $this->reach->targetAgent($context, $agentId);

        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', self::MSG_NO_SUCH_AGENT);
        }

        $this->agents->delete($agent);

        return new JsonResponse(['data' => ['deleted' => true, 'agent_id' => $agentId]]);
    }

    /**
     * Create the agent, or say why it cannot be created.
     *
     * @param array<string, mixed> $body
     * @return JsonResponse|ImportResult
     */
    private function createAgent(ApiKeyContext $context, array $body): JsonResponse|ImportResult
    {
        $groupId = $this->targetGroupId($context, $body);

        if ($groupId === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        // `group_id` is API-level metadata: it says which group the agent
        // belongs to, and it is not part of the template schema, so the
        // validator would (correctly) reject it under UNKNOWN_TOP_LEVEL_KEY.
        unset($body['group_id']);

        try {
            $this->effortService?->assertAgentDefault($this->defaultEffortFrom($body), $this->pendingAgent($groupId));

            return $this->agents->create($groupId, $this->createdBy($context), $body);
        } catch (InvalidTemplatePayloadException | MissingGroupPrincipalException | EffortValidationException $e) {
            return $this->unprocessable($this->errorCodeFor($e), $e->getMessage());
        }
    }

    /**
     * Apply the payload to the agent, or say why it cannot be applied.
     *
     * @param array<string, mixed> $body
     * @return JsonResponse|ImportResult
     */
    private function updateAgent(ApiKeyContext $context, int $agentId, array $body): JsonResponse|ImportResult
    {
        $agent = $this->reach->targetAgent($context, $agentId);

        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', self::MSG_NO_SUCH_AGENT);
        }

        try {
            $this->effortService?->assertAgentDefault($this->defaultEffortFrom($body), $agent);

            return $this->agents->update($agent, $body);
        } catch (InvalidTemplatePayloadException | EffortValidationException $e) {
            return $this->unprocessable($this->errorCodeFor($e), $e->getMessage());
        }
    }

    /**
     * The default effort the payload names, if any.
     *
     * @param array<string, mixed> $body
     */
    private function defaultEffortFrom(array $body): mixed
    {
        $agent = $body['agent'] ?? null;

        return is_array($agent) ? ($agent['default_effort'] ?? null) : null;
    }

    /**
     * The agent a create would produce, for the level set to check against.
     *
     * The agent does not exist yet, so the level set is the one its
     * principal's effective configuration offers.
     */
    private function pendingAgent(int $groupId): ?Agent
    {
        $principalId = $this->principalService?->principalForGroup($groupId)?->id;

        return $principalId === null ? null : new Agent(['principal_id' => $principalId]);
    }

    /**
     * The wire code a refusal carries.
     */
    private function errorCodeFor(Throwable $e): string
    {
        return $e instanceof EffortValidationException ? $e->errorCode() : 'VALIDATION_ERROR';
    }

    /**
     * The group the new agent belongs to.
     *
     * The group surface has exactly one — the key's own. The global surface
     * names it in the body. A group that does not exist is a group the key
     * cannot reach, so both answer with the same 404.
     *
     * @param array<string, mixed> $body
     */
    private function targetGroupId(ApiKeyContext $context, array $body): ?int
    {
        $groupId = $context->scope === ApiKeyScope::Group
            ? $context->groupId
            : $this->intField($body, 'group_id');

        if ($groupId === null || Group::find($groupId) === null) {
            return null;
        }

        return $groupId;
    }

    /**
     * The user a created agent is attributed to.
     *
     * The key's own creator: the admin who minted a global key, or the
     * group owner who minted a group key. It is the fallback for the
     * import path; the agent itself is owned by the group's principal.
     */
    private function createdBy(ApiKeyContext $context): int
    {
        return $context->key->created_by ?? 0;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $name): ?int
    {
        $value = $body[$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
