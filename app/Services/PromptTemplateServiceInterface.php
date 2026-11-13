<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Http\Exceptions\EffortValidationException;
use Spora\Services\Exceptions\AgentNotFoundException;

/**
 * Service interface for prompt template management.
 */
interface PromptTemplateServiceInterface
{
    /**
     * Get all templates for an agent.
     *
     * @return list<array>|null Returns null if agent not found
     */
    public function getTemplatesForAgent(int $agentId, int $userId): ?array;

    /**
     * Create a new template.
     *
     * @return array{template: array}
     * @throws AgentNotFoundException If the agent does not exist or the caller cannot write it
     * @throws EffortValidationException When the effort is not a level the agent's driver offers
     */
    public function createTemplate(int $agentId, int $userId, array $data): array;

    /**
     * Get a specific template.
     *
     * @return array{template: array}|null
     */
    public function getTemplate(int $templateId, int $agentId, int $userId): ?array;

    /**
     * Update a template.
     *
     * @return array{template: array}|null
     * @throws EffortValidationException When the effort is not a level the agent's driver offers
     */
    public function updateTemplate(int $templateId, int $agentId, int $userId, array $data): ?array;

    /**
     * Delete a template.
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteTemplate(int $templateId, int $agentId, int $userId): bool;
}
