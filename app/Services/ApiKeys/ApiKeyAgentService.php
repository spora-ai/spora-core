<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\AgentTemplates\AgentTemplate;
use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\AgentTemplates\ImportResult;
use Spora\Models\Agent;
use Spora\Services\Exceptions\InvalidTemplatePayloadException;
use Spora\Services\PrincipalService;

/**
 * Agent CRUD for the scoped API surfaces, in the Agent Template format.
 *
 * The format is the same one the import endpoint accepts, so there is one
 * shape to learn and one validator to trust. Create takes the payload
 * verbatim; update takes it as a merge — only the fields it names are
 * written, and a tool it does not name is left alone.
 */
final class ApiKeyAgentService
{
    public function __construct(
        private readonly AgentTemplateImporter $importer,
        private readonly AgentTemplateExporter $exporter,
        private readonly AgentTemplateValidator $validator,
        private readonly PrincipalService $principalService,
    ) {}

    /**
     * The agents the key may touch.
     *
     * @return list<Agent>
     */
    public function listAgents(ApiKeyReach $reach, ApiKeyContext $ctx): array
    {
        return $reach->agents($ctx)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * Create an agent in a group, from the import format.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidTemplatePayloadException when the payload is not a usable template
     * @throws MissingGroupPrincipalException when the group has no principal to own the agent
     */
    public function create(int $groupId, int $createdBy, array $payload): ImportResult
    {
        $validation = $this->validator->validate($payload);

        if (!$validation->isValid()) {
            throw new InvalidTemplatePayloadException($validation->errors());
        }

        $principalId = $this->principalService->principalForGroup($groupId)?->id;

        if ($principalId === null) {
            throw new MissingGroupPrincipalException(
                "Group {$groupId} has no principal to own an agent.",
            );
        }

        return $this->importer->importPayload($createdBy, $payload, (int) $principalId);
    }

    /**
     * Apply a payload to an agent that already exists.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidTemplatePayloadException when the payload is not a usable template
     */
    public function update(Agent $agent, array $payload): ImportResult
    {
        $validation = $this->validator->validatePartial($payload);

        if (!$validation->isValid()) {
            throw new InvalidTemplatePayloadException($validation->errors());
        }

        return $this->importer->applyToAgent((int) $agent->id, new AgentTemplate(raw: $payload, source: 'uploaded'));
    }

    /**
     * The agent in the shape the import format reads.
     *
     * @return array<string, mixed>
     */
    public function export(Agent $agent): array
    {
        return $this->exporter->export($agent)['template']->raw();
    }

    /**
     * Delete an agent and everything that cascades from it.
     *
     * The scope has already established that the key may reach this agent,
     * so there is no second visibility check here — the caller owns the
     * authorisation. The cascade is the schema's: the agent's chats, tools
     * and media go with it.
     */
    public function delete(Agent $agent): bool
    {
        return Capsule::table('agents')->where('id', (int) $agent->id)->delete() > 0;
    }
}
