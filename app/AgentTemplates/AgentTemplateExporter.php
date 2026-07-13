<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;

/**
 * Builds an {@see AgentTemplate} payload from a persisted Agent.
 *
 * **Settings are NEVER emitted.** No code path reads
 * {@see \Spora\Services\ToolConfigService::getEffectiveSettings()} or
 * {@see \Spora\Services\ToolConfigService::getRawAgentOverride()}.
 * The exporter walks only `agent_tools` and
 * `agent_tool_operation_overrides`. The companion
 * {@see AgentTemplateImporter::SETTINGS_NOT_EXPORTED_WARNING} string
 * is surfaced by the controller so the SPA can show an inline banner
 * before download.
 */
final class AgentTemplateExporter
{
    public function __construct(
        private readonly PluginLoader $pluginLoader,
    ) {}

    /**
     * @return array{
     *     template: AgentTemplate,
     *     inline_warning: string
     * }
     */
    public function export(Agent $agent): array
    {
        $tools = $this->buildToolsSection($agent);
        $agentBlock = $this->buildAgentBlock($agent);

        $raw = [
            '$schema'  => 'https://spora.dev/agent-template.schema.json',
            'id'       => $this->resolveTemplateId($agent),
            'name'     => $agent->name,
            'version'  => '1.0.0',
            'agent'    => $agentBlock,
            'tools'    => $tools,
            'required_plugins' => $this->buildRequiredPlugins($tools),
            'metadata' => [
                'category' => 'general',
                'icon'     => 'puzzle',
            ],
        ];

        if ($agent->description !== null && $agent->description !== '') {
            $raw['description'] = $agent->description;
        }

        $template = new AgentTemplate(
            raw: $raw,
            source: 'exported',
        );

        return [
            'template'       => $template,
            'inline_warning' => AgentTemplateImporter::SETTINGS_NOT_EXPORTED_WARNING,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildToolsSection(Agent $agent): array
    {
        $rows = AgentTool::where('agent_id', $agent->id)->get();
        $overrides = AgentToolOperationOverride::where('agent_id', $agent->id)
            ->get()
            ->groupBy('tool_class');

        $tools = [];
        foreach ($rows as $row) {
            $toolClass = $row->tool_class;
            $toolOps = $overrides->get($toolClass, collect());

            $operations = [];
            foreach ($toolOps as $op) {
                // Only emit operations that carry an explicit override.
                // Inherit-from-default rows (both fields null) are skipped
                // to keep the exported template minimal.
                if ($op->enabled === null && $op->default_requires_approval === null) {
                    continue;
                }
                $entry = ['name' => $op->operation];
                if ($op->enabled !== null) {
                    $entry['enabled'] = $op->enabled === 1;
                }
                if ($op->default_requires_approval !== null) {
                    // default_requires_approval=0 → auto_approve=true
                    $entry['auto_approve'] = $op->default_requires_approval === 0;
                }
                $operations[] = $entry;
            }

            $tools[] = [
                'tool_class' => $toolClass,
                'enabled'    => true,
                'operations' => $operations,
            ];
        }
        return $tools;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAgentBlock(Agent $agent): array
    {
        $block = [
            'max_steps'           => (int) $agent->max_steps,
            'allow_continuation'  => (bool) $agent->allow_followup,
            'retry_after_minutes' => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'         => (int) ($agent->max_retries ?? 0),
        ];
        if ($agent->description !== null && $agent->description !== '') {
            $block['description'] = $agent->description;
        }
        if ($agent->system_prompt !== null && $agent->system_prompt !== '') {
            $block['system_prompt'] = $agent->system_prompt;
        }
        return $block;
    }

    /**
     * Derive a template id from the agent name. The `core/` namespace is
     * reserved for Spora-shipped templates that ship with the framework;
     * a re-imported user export must NOT claim that namespace — operators
     * who want a different id can edit the file before import.
     *
     * Recipes don't have canonical ids (they're files on disk), so the
     * id here is a stable slug from the agent's display name.
     */
    private function resolveTemplateId(Agent $agent): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $agent->name) ?? '');
        $slug = trim($slug, '-');
        return substr($slug !== '' ? $slug : 'exported-agent', 0, 64);
    }

    /**
     * Walk the exported tools and collect the Composer package names of
     * every plugin that owns at least one of them. Built-in core tools
     * (no owning plugin) and tools whose plugin can't be resolved to a
     * package name (missing composer.json, uninstalled, …) are silently
     * dropped — the re-import operator already has core, and broken
     * entries would block the import entirely. Deduplicated; sorted for
     * stable output across runs (so a round-trip through this exporter
     * + the file system is deterministic).
     *
     * The output is `vendor/name` Composer identifiers (e.g.
     * `spora-ai/spora-plugin-media-archive`) — NOT the filesystem
     * slug. The slug is a directory name; only the package name
     * resolves against Packagist via `composer require <name>`.
     *
     * @param  list<array<string, mixed>>  $tools
     * @return list<string>
     */
    private function buildRequiredPlugins(array $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            $toolClass = is_string($tool['tool_class'] ?? null) ? $tool['tool_class'] : null;
            if ($toolClass === null) {
                continue;
            }
            $slug = $this->pluginLoader->getSlugForToolClass($toolClass);
            if ($slug === null) {
                continue;
            }
            $package = $this->pluginLoader->getComposerNameForSlug($slug);
            if ($package !== null) {
                $names[$package] = true;
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }
}
