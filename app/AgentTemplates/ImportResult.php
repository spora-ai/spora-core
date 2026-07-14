<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Spora\Models\Agent;

/**
 * Outcome of {@see AgentTemplateImporter::applyTemplate()} or
 * {@see AgentTemplateImporter::importPayload()}.
 *
 * Carries the created Agent, the per-tool summary (so the UI can
 * present a "what we did" report), and the aggregated warning list.
 * Traceability of which template the agent came from is logged
 * separately by the seeder / importer — agents.recipe_id was
 * removed because Agent Templates are files, not database entities.
 */
final class ImportResult
{
    /**
     * @param list<array{tool_class: string, enabled: bool, operations_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}> $toolsEnabled
     * @param list<array{code: string, severity: string, message: string, path?: string}> $warnings
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly array $toolsEnabled = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @return array{
     *     agent: Agent,
     *     tools_enabled: list<array<string, mixed>>,
     *     warnings: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'agent'         => $this->agent,
            'tools_enabled' => $this->toolsEnabled,
            'warnings'      => $this->warnings,
        ];
    }
}
