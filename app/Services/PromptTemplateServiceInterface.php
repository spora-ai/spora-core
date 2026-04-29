<?php

declare(strict_types=1);

namespace Spora\Services;

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
     */
    public function updateTemplate(int $templateId, int $agentId, int $userId, array $data): ?array;

    /**
     * Delete a template.
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteTemplate(int $templateId, int $agentId, int $userId): bool;
}