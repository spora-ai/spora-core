<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use ReflectionClass;
use Spora\Models\Agent;
use Spora\Models\AgentPicture;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;

/**
 * Builds an {@see AgentTemplate} payload from a persisted Agent.
 *
 * Password-typed keys and user/global cascade values are NEVER emitted.
 * Only the agent's own override row is included, and only when the operator
 * opts in via `includeSettings=true`.
 */
final class AgentTemplateExporter
{
    public const SETTINGS_EXPORT_INCLUDED_INFO = 'Included %d tool setting(s) for: %s. Passwords and inherited global/user values are NOT included — recipients must configure those in Settings → Tools after importing.';

    /**
     * Canonical $schema URL embedded in every exported template. Mirrors
     * `agent-template.schema.json`'s `$id` — served from docs.spora-ai.com so
     * editors (VSCode, JetBrains, ajv) can fetch the schema for validation.
     */
    public const SCHEMA_URL = 'https://docs.spora-ai.com/schemas/agent-template.schema.json';

    private readonly PrincipalResolver $resolver;

    public function __construct(
        private readonly PluginLoader $pluginLoader,
        private readonly ToolConfigService $toolConfig,
        private readonly ToolConfigSchemaInspector $schemaInspector,
        ?PrincipalResolver $resolver = null,
    ) {
        $this->resolver = $resolver ?? new PrincipalResolver();
    }

    /**
     * @return array{
     *     template: AgentTemplate,
     *     inline_warning?: string,
     *     inline_info?: string
     * }
     */
    public function export(Agent $agent, bool $includeSettings = false): array
    {
        [$tools, $settingsCount, $settingsTools] = $this->buildToolsSection($agent, $includeSettings);
        $agentBlock = $this->buildAgentBlock($agent);
        $metadata = $this->buildMetadata($agent);
        $principal = $this->buildPrincipalBlock($agent);

        $raw = [
            '$schema'  => self::SCHEMA_URL,
            'id'       => $this->resolveTemplateId($agent),
            'name'     => $agent->name,
            'version'  => '1.0.0',
            'agent'    => $agentBlock,
            'tools'    => $tools,
            'required_plugins' => $this->buildRequiredPlugins($tools),
            'metadata' => $metadata,
            'principal' => $principal,
        ];

        if ($agent->description !== null && $agent->description !== '') {
            $raw['description'] = $agent->description;
        }

        $template = new AgentTemplate(
            raw: $raw,
            source: 'exported',
        );

        $result = ['template' => $template];
        if ($settingsCount > 0) {
            // Drop the warning on the opt-in path — its "settings not included"
            // text would contradict inline_info. inline_info carries the
            // post-import setup reminder that the warning used to provide.
            $result['inline_info'] = sprintf(
                self::SETTINGS_EXPORT_INCLUDED_INFO,
                $settingsCount,
                implode(', ', $settingsTools),
            );
        } else {
            $result['inline_warning'] = AgentTemplateImporter::SETTINGS_NOT_EXPORTED_WARNING;
        }
        return $result;
    }

    /**
     * Build the `principal` block — minimal info so the importer knows
     * which target principal the export belongs to. The principal_id
     * is portable across a fresh install (the importer materialises the
     * target principal on demand), but the importer falls back to the
     * operator's user-principal when no principal info is present —
     * keeping the block explicit avoids the "imported as my private
     * agent, was meant to be shared" surprise. The `owner_user_id` is
     * resolved through PrincipalResolver so a group-principal-export
     * points at the group's first owner (the user the dashboard tools
     * prefer to surface as the "creator" of a shared agent).
     *
     * @return array<string, mixed>
     */
    private function buildPrincipalBlock(Agent $agent): array
    {
        $principalId = (int) $agent->principal_id;
        $principal = \Spora\Models\Principal::find($principalId);

        $block = ['principal_id' => $principalId];
        if ($principal !== null) {
            $block['type']         = (string) $principal->type;
            $block['user_id']      = $principal->user_id !== null ? (int) $principal->user_id : null;
            $block['group_id']     = $principal->group_id !== null ? (int) $principal->group_id : null;
            $block['owner_user_id'] = $this->resolver->ownerUserId($principalId);
        }

        return $block;
    }

    /**
     * Build the `metadata` block. Includes the agent's profile picture
     * fields (archetype / variant_key / palette_key) when present so a
     * re-import preserves the original look. Returns only the legacy
     * `category` + `icon` defaults when the agent has no picture row —
     * matches the pre-feature behaviour.
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(Agent $agent): array
    {
        $picture = AgentPicture::where('agent_id', $agent->id)->first();
        if ($picture === null) {
            return [
                'category' => 'general',
                'icon'     => 'puzzle',
            ];
        }

        $metadata = ['category' => 'general'];
        if ($picture->archetype !== null) {
            $metadata['archetype'] = $picture->archetype;
            if ($picture->variant_key !== null) {
                $metadata['variant_key'] = $picture->variant_key;
            }
        }
        if ($picture->palette_key !== null) {
            $metadata['palette_key'] = $picture->palette_key;
        }
        $metadata['icon'] = 'puzzle';
        return $metadata;
    }

    /**
     * @return array{list<array<string, mixed>>, int, list<string>}
     */
    private function buildToolsSection(Agent $agent, bool $includeSettings): array
    {
        $rows = AgentTool::where('agent_id', $agent->id)->get();
        $overrides = AgentToolOperationOverride::where('agent_id', $agent->id)
            ->get()
            ->groupBy('tool_class');

        $tools = [];
        $settingsCount = 0;
        $settingsTools = [];
        foreach ($rows as $row) {
            $toolClass = $row->tool_class;
            $operations = $this->buildOperationOverrides($overrides->get($toolClass, collect()));

            $entry = [
                'tool_class' => $toolClass,
                'enabled'    => true,
                'operations' => $operations,
            ];
            $settings = $includeSettings ? $this->exportToolSettings($toolClass, (int) $agent->id) : [];
            if ($settings !== []) {
                $entry['settings'] = $settings;
                $settingsCount += count($settings);
                $settingsTools[] = $this->resolveToolDisplayName($toolClass);
            }
            $tools[] = $entry;
        }
        return [$tools, $settingsCount, $settingsTools];
    }

    /**
     * Build the per-tool `operations[]` array, skipping operation rows that
     * carry no explicit override (both columns null).
     *
     * @param \Illuminate\Support\Collection<int, AgentToolOperationOverride> $toolOps
     * @return list<array<string, mixed>>
     */
    private function buildOperationOverrides(\Illuminate\Support\Collection $toolOps): array
    {
        $operations = [];
        foreach ($toolOps as $op) {
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
        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportToolSettings(string $toolClass, int $agentId): array
    {
        $override = $this->schemaInspector->normalizeMultiSelectValuesForTemplate(
            $toolClass,
            $this->toolConfig->getRawAgentOverride($toolClass, $agentId),
        );
        $exportable = array_flip($this->schemaInspector->getExportableKeys($toolClass));
        // Drop null/''/empty-array — the override row uses these as "inherit
        // parent" markers; emitting them would tell importers to clear values
        // the operator never customised.
        return array_filter(
            array_intersect_key($override, $exportable),
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    /**
     * Resolve a tool class to the human-readable name from its #[Tool]
     * attribute, falling back to the class basename.
     */
    private function resolveToolDisplayName(string $toolClass): string
    {
        $basename = $this->toolClassBasename($toolClass);
        if (!class_exists($toolClass)) {
            return $basename;
        }
        $reflection = new ReflectionClass($toolClass);
        foreach ($reflection->getAttributes(Tool::class) as $attr) {
            return $this->displayNameFromAttribute($attr->newInstance());
        }
        return $basename;
    }

    private function displayNameFromAttribute(Tool $instance): string
    {
        if ($instance->displayName !== null && $instance->displayName !== '') {
            return $instance->displayName;
        }
        return $instance->name;
    }

    private function toolClassBasename(string $toolClass): string
    {
        $parts = explode('\\', $toolClass);
        $last = end($parts);
        return $last !== '' ? $last : $toolClass;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAgentBlock(Agent $agent): array
    {
        $block = [
            'max_steps'           => (int) $agent->max_steps,
            'allow_followup'      => (bool) $agent->allow_followup,
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
            $package = $this->resolvePackageForTool($tool);
            if ($package !== null) {
                $names[$package] = true;
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }

    /**
     * Resolve a tool entry to the Composer `vendor/name` package that
     * ships its `tool_class`. Returns null when the tool class is not
     * string-coercible, the plugin isn't loaded, or the plugin has no
     * resolvable Composer package — all silent drops because broken
     * entries would otherwise block the entire import.
     *
     * @param  array<string, mixed>  $tool
     */
    private function resolvePackageForTool(array $tool): ?string
    {
        $toolClass = is_string($tool['tool_class'] ?? null) ? $tool['tool_class'] : null;
        if ($toolClass === null) {
            return null;
        }
        $slug = $this->pluginLoader->getSlugForToolClass($toolClass);
        if ($slug === null) {
            return null;
        }
        return $this->pluginLoader->getComposerNameForSlug($slug);
    }
}
