<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Service interface for mail template management.
 */
interface MailTemplateServiceInterface
{
    /**
     * Get all mail templates (names only).
     *
     * @return list<array>
     */
    public function getAllTemplates(): array;

    /**
     * Get a specific mail template.
     *
     * @return array{mail_template: array}|null
     */
    public function getTemplate(int $templateId): ?array;

    /**
     * Create a new mail template.
     *
     * @return array{mail_template: array}
     */
    public function createTemplate(array $data): array;

    /**
     * Update a mail template.
     *
     * @return array{mail_template: array}|null
     */
    public function updateTemplate(int $templateId, array $data): ?array;

    /**
     * Delete a mail template.
     *
     * @return bool True if deleted, false if not found or system template
     */
    public function deleteTemplate(int $templateId): bool;

    /**
     * Preview a mail template with variables.
     *
     * @return array{name: string, subject: string, body_text: string, body_html: string}
     */
    public function previewTemplate(string $name, array $variables): array;
}
