<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use RuntimeException;
use Spora\Core\Paths;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;

/**
 * Applies an Agent Template to the database: creates a new Agent row,
 * enables the template's tools (skipping any whose tool_class is not
 * currently registered), and writes per-operation auto-approve overrides.
 *
 * Settings (passwords, secrets) are NEVER written by this importer —
 * the template shape excludes them. Missing required settings still get
 * a row inserted with a TOOL_NEEDS_CONFIGURATION warning; the operator
 * configures them later in Settings → Tools.
 *
 * Plugins are NEVER auto-installed. A template whose required_plugins
 * slugs are not loaded produces a PLUGIN_MISSING warning but does not
 * abort the import.
 */
final class AgentTemplateImporter
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Inline warning surfaced on every export response so operators
     * don't accidentally ship credentials in a template file.
     */
    public const SETTINGS_NOT_EXPORTED_WARNING = 'Settings (passwords, API keys) are NOT included in this export. Recipients must configure them in Settings → Tools after importing.';

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        private readonly PluginLoader $plugins,
        private readonly Paths $paths,
    ) {}

    /**
     * Look up a built-in template by id and apply it.
     *
     * @throws RuntimeException when the template id is unknown.
     */
    public function applyTemplate(int $userId, string $templateId): ImportResult
    {
        $scanner = new AgentTemplateScanner(
            directories: $this->collectDirectories(),
        );

        foreach ($scanner->scan() as $template) {
            if ($template->id() === $templateId) {
                return $this->apply($userId, $template);
            }
        }

        throw new RuntimeException("Agent template '{$templateId}' not found.");
    }

    /**
     * Apply a raw payload (from the upload endpoint). The caller is
     * expected to have already validated it via {@see AgentTemplateValidator}.
     *
     * @param array<string, mixed> $raw
     */
    public function importPayload(int $userId, array $raw): ImportResult
    {
        $template = new AgentTemplate(raw: $raw, source: 'uploaded');
        return $this->apply($userId, $template);
    }

    /**
     * Internal: apply an AgentTemplate to the database. Wrapped in a
     * transaction so a partial failure rolls back the whole import.
     */
    private function apply(int $userId, AgentTemplate $template): ImportResult
    {
        $warnings = $template->warnings();
        $toolsEnabled = [];

        $registeredTools = $this->toolConfig->getRegisteredToolClasses();
        $installedPlugins = array_keys($this->plugins->getPlugins());

        // Aggregate plugin-missing warnings for required_plugins
        foreach ($template->requiredPlugins() as $slug) {
            if (!in_array($slug, $installedPlugins, true)) {
                $warnings[] = [
                    'code'     => 'PLUGIN_MISSING',
                    'severity' => 'warning',
                    'message'  => sprintf("Plugin '%s' is required but not installed.", $slug),
                    'path'     => 'required_plugins',
                ];
            }
        }

        $agentId = Capsule::connection()->transaction(function () use ($userId, $template, $registeredTools, &$warnings, &$toolsEnabled): int {
            $agentId = $this->createAgent($userId, $template);

            foreach ($template->tools() as $toolEntry) {
                $toolClass = (string) ($toolEntry['tool_class'] ?? '');
                if ($toolClass === '') {
                    continue;
                }

                if (!in_array($toolClass, $registeredTools, true)) {
                    $warnings[] = [
                        'code'     => 'TOOL_PLUGIN_MISSING',
                        'severity' => 'warning',
                        'message'  => sprintf("Tool '%s' is not currently registered (plugin missing or unloaded). Skipping.", $toolClass),
                        'path'     => 'tools[].tool_class',
                    ];
                    continue;
                }

                $enabled = (bool) ($toolEntry['enabled'] ?? false);
                if (!$enabled) {
                    continue;
                }

                $now = date(self::DATETIME_FORMAT);
                AgentTool::updateOrCreate(
                    ['agent_id' => $agentId, 'tool_class' => $toolClass],
                    [
                        'tool_name'  => $this->resolveToolName($toolClass),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId);
                $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

                $toolWarnings = [];
                if ($missing !== []) {
                    $toolWarnings[] = [
                        'code'     => 'TOOL_NEEDS_CONFIGURATION',
                        'severity' => 'warning',
                        'message'  => sprintf(
                            "Tool '%s' is enabled but missing required settings: %s.",
                            $toolClass,
                            implode(', ', $missing),
                        ),
                        'path'     => 'tools[].tool_class',
                    ];
                    $warnings = array_merge($warnings, $toolWarnings);
                }

                $opsApplied = 0;
                foreach (($toolEntry['operations'] ?? []) as $op) {
                    if (!is_array($op)) {
                        continue;
                    }
                    $opName = (string) ($op['name'] ?? '');
                    if ($opName === '' || !$this->isKnownOperation($toolClass, $opName)) {
                        continue;
                    }

                    $row = ['agent_id' => $agentId, 'tool_class' => $toolClass, 'operation' => $opName];

                    // Preserve created_at on existing rows so the upsert
                    // doesn't reset the timestamp; on insert we set both.
                    $existing = AgentToolOperationOverride::where($row)->first();
                    $update = ['updated_at' => $now];
                    if (array_key_exists('enabled', $op)) {
                        $update['enabled'] = $op['enabled'] ? 1 : 0;
                    }
                    if (array_key_exists('auto_approve', $op)) {
                        // auto_approve=true → no approval required → default_requires_approval=0
                        $update['default_requires_approval'] = $op['auto_approve'] ? 0 : 1;
                    }

                    if ($existing === null) {
                        $update['created_at'] = $now;
                    }

                    AgentToolOperationOverride::updateOrCreate($row, $update);
                    $opsApplied++;
                }

                $toolsEnabled[] = [
                    'tool_class'         => $toolClass,
                    'enabled'            => true,
                    'operations_applied' => $opsApplied,
                    'warnings'           => $toolWarnings,
                ];
            }

            return $agentId;
        });

        $agent = Agent::find($agentId);
        if ($agent === null) {
            throw new RuntimeException("Agent {$agentId} disappeared mid-import.");
        }

        return new ImportResult(
            agent: $agent,
            toolsEnabled: $toolsEnabled,
            warnings: $warnings,
        );
    }

    private function createAgent(int $userId, AgentTemplate $template): int
    {
        $agent = $template->agent();
        $now = date(self::DATETIME_FORMAT);
        $allowFollowup = (bool) ($agent['allow_continuation'] ?? true);

        return Capsule::table('agents')->insertGetId([
            'user_id'             => $userId,
            'name'                => $this->resolveAgentName($template),
            'description'         => $this->nullIfEmpty($agent['description'] ?? null),
            'recipe_id'           => $template->id(),
            'system_prompt'       => $this->nullIfEmpty($agent['system_prompt'] ?? null),
            'max_steps'           => (int) ($agent['max_steps'] ?? 10),
            'allow_followup'      => $allowFollowup ? 1 : 0,
            'retry_after_minutes' => (int) ($agent['retry_after_minutes'] ?? 0),
            'max_retries'         => (int) ($agent['max_retries'] ?? 0),
            'is_active'           => 1,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
    }

    private function resolveAgentName(AgentTemplate $template): string
    {
        $name = $template->name();
        return $name !== '' ? $name : $template->id();
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Resolve the tool_name from the tool's #[Tool] attribute. Falls back
     * to the class basename if the attribute is missing (defensive only;
     * registered tool classes always carry the attribute).
     */
    private function resolveToolName(string $toolClass): string
    {
        if (!class_exists($toolClass)) {
            $parts = explode('\\', $toolClass);
            return end($parts) ?: $toolClass;
        }
        $reflection = new ReflectionClass($toolClass);
        $attrs = $reflection->getAttributes(Tool::class);
        if ($attrs === []) {
            $parts = explode('\\', $toolClass);
            return end($parts) ?: $toolClass;
        }
        /** @var Tool $tool */
        $tool = $attrs[0]->newInstance();
        return $tool->name;
    }

    private function isKnownOperation(string $toolClass, string $operation): bool
    {
        if (!class_exists($toolClass)) {
            return false;
        }
        $reflection = new ReflectionClass($toolClass);
        foreach ($reflection->getAttributes(ToolOperation::class) as $attr) {
            /** @var ToolOperation $instance */
            $instance = $attr->newInstance();
            if ($instance->name === $operation) {
                return true;
            }
        }
        return false;
    }

    /**
     * Aggregate directories: project overrides win over framework default,
     * plus everything contributed by loaded plugins.
     *
     * @return list<string>
     */
    private function collectDirectories(): array
    {
        $dirs = [];
        foreach ($this->paths->agentTemplatesPaths() as $p) {
            if (is_dir($p)) {
                $dirs[] = $p;
            }
        }
        foreach ($this->plugins->agentTemplatePaths() as $p) {
            if (is_dir($p)) {
                $dirs[] = $p;
            }
        }
        return $dirs;
    }
}
