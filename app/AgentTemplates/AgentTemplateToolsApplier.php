<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use ReflectionClass;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;

/**
 * Applies the `tools[]` block of an agent template to the database:
 * upserts `agent_tools` rows, writes per-operation overrides via
 * {@see ToolConfigService}, and surfaces TOOL_PLUGIN_MISSING /
 * TOOL_NEEDS_CONFIGURATION warnings.
 *
 * Extracted from {@see AgentTemplateImporter} so the importer stays under
 * the SonarCloud 20-method-per-class ceiling (S1448). The split mirrors
 * the natural read/write seam — orchestration lives in the importer,
 * per-tool writes live here.
 */
final class AgentTemplateToolsApplier
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        private readonly ?AgentTemplateSettingsApplier $settingsApplier = null,
    ) {}

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
     * @return list<array{tool_class: string, enabled: bool, operations_applied: int, settings_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}>
     */
    public function applyTools(
        int $agentId,
        AgentTemplate $template,
        array $registeredTools,
        array &$warnings,
    ): array {
        $toolsEnabled = [];
        foreach ($template->tools() as $toolIndex => $toolEntry) {
            $result = $this->applyTool($agentId, $toolEntry, $registeredTools, $toolIndex, $warnings);
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
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     * @return array{skipped: bool, enabled: bool, warning: ?array{code: string, severity: string, message: string, path?: string}, summary: ?array{tool_class: string, enabled: bool, operations_applied: int, settings_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}}
     */
    private function applyTool(
        int $agentId,
        array $toolEntry,
        array $registeredTools,
        int $toolIndex,
        array &$warnings,
    ): array {
        $empty = ['skipped' => false, 'enabled' => false, 'warning' => null, 'summary' => null];

        $toolClass = (string) ($toolEntry['tool_class'] ?? '');
        $missingWarning = $this->buildToolPluginMissingWarning($toolClass, $registeredTools);

        if ($missingWarning !== null) {
            return [
                'skipped' => true,
                'enabled' => false,
                'warning' => $missingWarning,
                'summary' => null,
            ];
        }

        if ($toolClass === '' || !(bool) ($toolEntry['enabled'] ?? false)) {
            return $empty;
        }

        return $this->applyEnabledTool($agentId, $toolClass, $toolEntry, $toolIndex, $warnings);
    }

    /**
     * Build the TOOL_PLUGIN_MISSING warning when the tool's class isn't
     * currently registered with ToolConfigService. Returns null when the
     * class is empty or registered (caller should fall through to the
     * next gate).
     *
     * @param list<string> $registeredTools
     * @return ?array{code: string, severity: string, message: string, path?: string}
     */
    private function buildToolPluginMissingWarning(string $toolClass, array $registeredTools): ?array
    {
        if ($toolClass === '' || in_array($toolClass, $registeredTools, true)) {
            return null;
        }
        return [
            'code'     => 'TOOL_PLUGIN_MISSING',
            'severity' => 'warning',
            'message'  => sprintf("Tool '%s' is not currently registered (plugin missing or unloaded). Skipping.", $toolClass),
            'path'     => 'tools[].tool_class',
        ];
    }

    /**
     * Persist the agent_tool row, evaluate missing-required-config, and
     * upsert per-operation overrides. Returns the enabled-shape result.
     *
     * @param array<string, mixed> $toolEntry
     * @param array<int, array{code: string, severity: string, message: string, path?: string}> $warnings
     * @return array{skipped: bool, enabled: bool, warning: ?array{code: string, severity: string, message: string, path?: string}, summary: ?array{tool_class: string, enabled: bool, operations_applied: int, settings_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}}
     */
    private function applyEnabledTool(
        int $agentId,
        string $toolClass,
        array $toolEntry,
        int $toolIndex,
        array &$warnings,
    ): array {
        $now = date(self::DATETIME_FORMAT);
        AgentTool::updateOrCreate(
            ['agent_id' => $agentId, 'tool_class' => $toolClass],
            [
                'tool_name'  => $this->resolveToolName($toolClass),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $settings = is_array($toolEntry['settings'] ?? null) ? $toolEntry['settings'] : [];
        $settingsApplied = $this->settingsApplier?->apply(
            $agentId,
            $toolClass,
            $settings,
            $toolIndex,
            $warnings,
        ) ?? 0;

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
            'summary'  => $this->buildEnabledSummary($toolClass, $opsApplied, $settingsApplied, $toolWarning),
        ];
    }

    /**
     * Build the per-tool summary entry for an enabled tool. Extracted to keep
     * `applyTool`'s return-count under the S1142 ceiling.
     *
     * @return array{tool_class: string, enabled: bool, operations_applied: int, settings_applied: int, warnings: list<array{code: string, severity: string, message: string, path?: string}>}
     */
    private function buildEnabledSummary(
        string $toolClass,
        int $opsApplied,
        int $settingsApplied,
        ?array $toolWarning,
    ): array {
        return [
            'tool_class'         => $toolClass,
            'enabled'            => true,
            'operations_applied' => $opsApplied,
            'settings_applied'   => $settingsApplied,
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
}
