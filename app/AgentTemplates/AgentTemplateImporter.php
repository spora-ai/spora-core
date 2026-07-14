<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use Spora\AgentTemplates\Exceptions\AgentImportFailedException;
use Spora\AgentTemplates\Exceptions\AgentTemplateNotFoundException;
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
     * @throws AgentTemplateNotFoundException when the template id is unknown.
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

        throw new AgentTemplateNotFoundException("Agent template '{$templateId}' not found.");
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
     * Internal: apply an AgentTemplate to the database. The implementation
     * is split into small helpers so each method stays under the cognitive
     * complexity ceiling; the orchestration lives here.
     *
     * @throws AgentImportFailedException when the post-insert sanity check fails.
     */
    private function apply(int $userId, AgentTemplate $template): ImportResult
    {
        $warnings = $template->warnings();
        $registeredTools = $this->toolConfig->getRegisteredToolClasses();

        $this->collectPluginWarnings($template, $warnings);

        // Transaction returns the tuple (agentId, toolsEnabled) so we
        // avoid needing a by-ref parameter on applyTools. The outer
        // unpack keeps the closure signature simple.
        [$agentId, $toolsEnabled] = Capsule::connection()->transaction(
            function () use ($userId, $template, $registeredTools, &$warnings): array {
                $agentId = $this->createAgent($userId, $template);
                $toolsEnabled = $this->applyTools($agentId, $template, $registeredTools, $warnings);
                return [$agentId, $toolsEnabled];
            },
        );

        $agent = Agent::find($agentId);
        if ($agent === null) {
            throw new AgentImportFailedException("Agent {$agentId} disappeared mid-import.");
        }

        return new ImportResult(
            agent: $agent,
            toolsEnabled: $toolsEnabled,
            warnings: $warnings,
        );
    }

    /**
     * Aggregate PLUGIN_MISSING warnings for any `required_plugins` slug that
     * is not currently loaded. Non-fatal — operators install plugins manually.
     *
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     */
    private function collectPluginWarnings(AgentTemplate $template, array &$warnings): void
    {
        $installedPlugins = array_keys($this->plugins->getPlugins());
        foreach ($template->requiredPlugins() as $slug) {
            if (in_array($slug, $installedPlugins, true)) {
                continue;
            }
            $warnings[] = [
                'code'     => 'PLUGIN_MISSING',
                'severity' => 'warning',
                'message'  => sprintf("Plugin '%s' is required but not installed.", $slug),
                'path'     => 'required_plugins',
            ];
        }
    }

    /**
     * Walk the template's tools array and return the per-tool summary.
     * For each entry:
     * - tool_class not registered → TOOL_PLUGIN_MISSING warning, no row.
     * - tool disabled → no row.
     * - tool enabled + missing global config → row + TOOL_NEEDS_CONFIGURATION warning.
     *
     * Returns the tools-enabled list and pushes any per-tool warnings
     * onto $warnings. Returning (instead of using a by-ref parameter)
     * keeps PHPStan's type inference simple for the nested array shape.
     *
     * @param list<string> $registeredTools
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     * @return list<array{tool_class: string, enabled: bool, operations_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}>
     */
    private function applyTools(
        int $agentId,
        AgentTemplate $template,
        array $registeredTools,
        array &$warnings,
    ): array {
        $toolsEnabled = [];
        foreach ($template->tools() as $toolEntry) {
            $result = $this->applyTool($agentId, $toolEntry, $registeredTools);
            if ($result['skipped']) {
                if ($result['warning'] !== null) {
                    $warnings[] = $result['warning'];
                }
                continue;
            }
            if ($result['enabled']) {
                $toolsEnabled[] = $result['summary'];
                if ($result['warning'] !== null) {
                    $warnings[] = $result['warning'];
                }
            }
        }
        return $toolsEnabled;
    }

    /**
     * Apply a single tool entry. Returns the per-tool outcome so the
     * caller can update warnings[] / toolsEnabled[] without nesting
     * conditionals. Keeping the logic here keeps `applyTools` flat.
     *
     * @param array<string, mixed> $toolEntry
     * @param list<string> $registeredTools
     * @return array{skipped: bool, enabled: bool, warning: ?array{code: string, severity: string, message: string, path?: string}, summary: ?array{tool_class: string, enabled: bool, operations_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}}
     */
    private function applyTool(int $agentId, array $toolEntry, array $registeredTools): array
    {
        $empty = ['skipped' => false, 'enabled' => false, 'warning' => null, 'summary' => null];

        $toolClass = (string) ($toolEntry['tool_class'] ?? '');
        if ($toolClass === '') {
            return $empty;
        }

        if (!in_array($toolClass, $registeredTools, true)) {
            return [
                'skipped'  => true,
                'enabled'  => false,
                'warning'  => [
                    'code'     => 'TOOL_PLUGIN_MISSING',
                    'severity' => 'warning',
                    'message'  => sprintf("Tool '%s' is not currently registered (plugin missing or unloaded). Skipping.", $toolClass),
                    'path'     => 'tools[].tool_class',
                ],
                'summary'  => null,
            ];
        }

        if (!(bool) ($toolEntry['enabled'] ?? false)) {
            return $empty;
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

        $missing = $this->toolConfig->getMissingRequiredSettings(
            $toolClass,
            $this->toolConfig->getEffectiveSettings($toolClass, $agentId),
        );

        $toolWarning = null;
        if ($missing !== []) {
            $toolWarning = [
                'code'     => 'TOOL_NEEDS_CONFIGURATION',
                'severity' => 'warning',
                'message'  => sprintf(
                    "Tool '%s' is enabled but missing required settings: %s.",
                    $toolClass,
                    implode(', ', $missing),
                ),
                'path'     => 'tools[].tool_class',
            ];
        }

        $opsApplied = $this->applyOperations($agentId, $toolClass, $toolEntry['operations'] ?? []);

        return [
            'skipped'  => false,
            'enabled'  => true,
            'warning'  => $toolWarning,
            'summary'  => $this->buildEnabledSummary($toolClass, $opsApplied, $toolWarning),
        ];
    }

    /**
     * Build the per-tool summary entry for an enabled tool. Extracted to keep
     * `applyTool`'s return-count under the S1142 ceiling.
     *
     * @return array{tool_class: string, enabled: bool, operations_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}
     */
    private function buildEnabledSummary(string $toolClass, int $opsApplied, ?array $toolWarning): array
    {
        return [
            'tool_class'         => $toolClass,
            'enabled'            => true,
            'operations_applied' => $opsApplied,
            'warnings'           => $toolWarning === null ? [] : [$toolWarning],
        ];
    }

    /**
     * Upsert per-operation overrides for an enabled tool. Operations whose
     * name is not declared by the tool are silently skipped — they would
     * be a no-op at runtime anyway. Returns the count of operations actually
     * applied so the caller can report it in `tools_enabled[].operations_applied`.
     *
     * @param array<int, mixed> $operations
     */
    private function applyOperations(int $agentId, string $toolClass, array $operations): int
    {
        $applied = 0;
        foreach ($operations as $op) {
            if ($this->shouldSkipOperation($op, $toolClass)) {
                continue;
            }

            $opName = (string) $op['name'];
            $this->persistOperationOverride($agentId, $toolClass, $opName, $op);
            $applied++;
        }
        return $applied;
    }

    /**
     * True when the operation entry is not a map, has no name, or names a
     * operation the tool doesn't actually declare. Extracted so the
     * `applyOperations` loop stays under the cognitive-complexity ceiling.
     *
     * @param mixed $op
     */
    private function shouldSkipOperation(mixed $op, string $toolClass): bool
    {
        if (!is_array($op)) {
            return true;
        }
        $opName = (string) ($op['name'] ?? '');
        if ($opName === '' || !$this->isKnownOperation($toolClass, $opName)) {
            return true;
        }
        return false;
    }

    /**
     * Build the upsert payload for a single operation override and write it.
     * Splits the conditional column updates out of the loop body to keep
     * the caller's cognitive complexity below the S3776 ceiling.
     *
     * @param array<string, mixed> $op
     */
    private function persistOperationOverride(int $agentId, string $toolClass, string $opName, array $op): void
    {
        $row = ['agent_id' => $agentId, 'tool_class' => $toolClass, 'operation' => $opName];
        $existing = AgentToolOperationOverride::where($row)->first();

        $update = ['updated_at' => date(self::DATETIME_FORMAT)];
        if (array_key_exists('enabled', $op)) {
            $update['enabled'] = $op['enabled'] ? 1 : 0;
        }
        if (array_key_exists('auto_approve', $op)) {
            // auto_approve=true → no approval required → default_requires_approval=0
            $update['default_requires_approval'] = $op['auto_approve'] ? 0 : 1;
        }
        if ($existing === null) {
            $update['created_at'] = date(self::DATETIME_FORMAT);
        }

        AgentToolOperationOverride::updateOrCreate($row, $update);
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
