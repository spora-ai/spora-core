<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use ReflectionClass;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;

/**
 * Validates a parsed Agent Template payload.
 *
 * Validation is manual (no JSON Schema library) to match the
 * {@see \Spora\Plugins\PluginLoader} convention and keep the runtime
 * dependency surface small. The companion `agent-template.schema.json`
 * at the framework root mirrors these rules for editor / tooling support.
 *
 * Errors make the template unusable; warnings are advisory and surface
 * to the operator during import (missing plugins, missing required
 * settings, unknown operation names, …). Settings fields are NEVER
 * validated — they do not exist in the template shape.
 */
final class AgentTemplateValidator
{
    /**
     * Accepts both namespaced (`<source>/<slug>`) and bare (`<slug>`)
     * template ids. The scanner additionally enforces that built-in /
     * plugin templates carry a matching namespace; the validator
     * accepts a bare slug so user-exported files round-trip cleanly.
     */
    private const ID_PATTERN = '/^([a-z0-9][a-z0-9_-]{0,63}\/)?[a-z0-9][a-z0-9_-]{0,63}$/';
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';
    private const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+([+-].+)?$/';

    private const ALLOWED_TOP_KEYS = [
        '$schema', 'id', 'name', 'description', 'version', 'agent', 'tools',
        'required_plugins', 'metadata',
    ];

    private const ALLOWED_AGENT_KEYS = [
        'description', 'system_prompt', 'notes', 'max_steps',
        'allow_continuation', 'retry_after_minutes', 'max_retries',
    ];

    private const ALLOWED_METADATA_KEYS = ['category', 'icon'];

    private const ALLOWED_CATEGORIES = [
        'general', 'productivity', 'research', 'communication', 'media', 'data', 'automation',
    ];

    /**
     * @param array<string, mixed> $raw
     */
    public function validate(array $raw): ValidationResult
    {
        $result = new ValidationResult();

        if ($raw === []) {
            $result->addError([
                'code'     => 'EMPTY_PAYLOAD',
                'severity' => 'error',
                'message'  => 'Template payload is empty.',
            ]);
            return $result;
        }

        $this->validateTopLevelKeys($raw, $result);
        $this->validateStringField($raw, 'id', self::ID_PATTERN, $result, true);
        $this->validateStringField($raw, 'name', null, $result, true);
        $this->validateStringField($raw, 'version', self::VERSION_PATTERN, $result, true);

        if (!array_key_exists('agent', $raw) || !is_array($raw['agent'])) {
            $result->addError([
                'code'     => 'AGENT_REQUIRED',
                'severity' => 'error',
                'message'  => "Field 'agent' is required and must be an object.",
                'path'     => 'agent',
            ]);
        } else {
            $this->validateAgentBlock($raw['agent'], $result);
            if (!isset($raw['agent']['system_prompt']) || trim((string) $raw['agent']['system_prompt']) === '') {
                $result->addWarning([
                    'code'     => 'SYSTEM_PROMPT_MISSING',
                    'severity' => 'warning',
                    'message'  => "Field 'agent.system_prompt' is empty — the agent will rely entirely on tool descriptions.",
                    'path'     => 'agent.system_prompt',
                ]);
            }
        }

        $this->validateTools($raw, $result);
        $this->validateRequiredPlugins($raw, $result);
        $this->validateMetadata($raw, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validateTopLevelKeys(array $raw, ValidationResult $result): void
    {
        foreach (array_keys($raw) as $key) {
            if (in_array($key, self::ALLOWED_TOP_KEYS, true)) {
                continue;
            }
            $result->addError([
                'code'     => 'UNKNOWN_TOP_LEVEL_KEY',
                'severity' => 'error',
                'message'  => sprintf("Unknown top-level field '%s'.", $key),
                'path'     => $key,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validateStringField(
        array $raw,
        string $field,
        ?string $pattern,
        ValidationResult $result,
        bool $required,
    ): void {
        if (!array_key_exists($field, $raw)) {
            if ($required) {
                $result->addError([
                    'code'     => strtoupper($field) . '_REQUIRED',
                    'severity' => 'error',
                    'message'  => sprintf("Field '%s' is required.", $field),
                    'path'     => $field,
                ]);
            }
            return;
        }

        $value = $raw[$field];
        if (!is_string($value) || $value === '') {
            $result->addError([
                'code'     => strtoupper($field) . '_INVALID',
                'severity' => 'error',
                'message'  => sprintf("Field '%s' must be a non-empty string.", $field),
                'path'     => $field,
            ]);
            return;
        }

        if ($pattern !== null && !preg_match($pattern, $value)) {
            $result->addError([
                'code'     => strtoupper($field) . '_PATTERN',
                'severity' => 'error',
                'message'  => sprintf("Field '%s' does not match pattern %s.", $field, $pattern),
                'path'     => $field,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $agent
     */
    private function validateAgentBlock(array $agent, ValidationResult $result): void
    {
        foreach (array_keys($agent) as $key) {
            if (in_array($key, self::ALLOWED_AGENT_KEYS, true)) {
                continue;
            }
            $result->addError([
                'code'     => 'UNKNOWN_AGENT_KEY',
                'severity' => 'error',
                'message'  => sprintf("Unknown field 'agent.%s'.", $key),
                'path'     => 'agent.' . $key,
            ]);
        }

        if (isset($agent['max_steps']) && is_int($agent['max_steps']) && ($agent['max_steps'] < 1 || $agent['max_steps'] > 100)) {
            $result->addError([
                'code'     => 'MAX_STEPS_RANGE',
                'severity' => 'error',
                'message'  => "Field 'agent.max_steps' must be between 1 and 100.",
                'path'     => 'agent.max_steps',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validateTools(array $raw, ValidationResult $result): void
    {
        if (!array_key_exists('tools', $raw)) {
            return;
        }

        $tools = $raw['tools'];
        if (!is_array($tools) || !array_is_list($tools)) {
            $result->addError([
                'code'     => 'TOOLS_NOT_LIST',
                'severity' => 'error',
                'message'  => "Field 'tools' must be an array.",
                'path'     => 'tools',
            ]);
            return;
        }

        $seenClasses = [];
        foreach ($tools as $index => $tool) {
            $this->validateToolEntry($tool, $index, $seenClasses, $result);
        }
    }

    /**
     * @param array<string, bool> $seenClasses
     */
    private function validateToolEntry(mixed $tool, int $index, array &$seenClasses, ValidationResult $result): void
    {
        $path = "tools[{$index}]";

        // After the outer loop in validateTools() filters out non-list
        // payloads, every $tool entry is either an array or a non-array
        // (a scalar). The !is_array branch handles the latter.
        if (!is_array($tool)) {
            $result->addError([
                'code'     => 'TOOL_NOT_OBJECT',
                'severity' => 'error',
                'message'  => "Tool entry must be an object.",
                'path'     => $path,
            ]);
            return;
        }

        $toolClass = $this->validateToolClass($tool, $path, $seenClasses, $result);
        $this->validateToolEnabledFlag($tool, $path, $result);
        $this->validateToolOperations($tool, $toolClass, $path, $result);
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, bool> $seenClasses
     * @return string The validated tool_class (or '' when missing/invalid).
     */
    private function validateToolClass(array $tool, string $path, array &$seenClasses, ValidationResult $result): string
    {
        $toolClass = $tool['tool_class'] ?? null;
        if (!is_string($toolClass) || $toolClass === '') {
            $result->addError([
                'code'     => 'TOOL_CLASS_REQUIRED',
                'severity' => 'error',
                'message'  => "Tool entry is missing 'tool_class'.",
                'path'     => "{$path}.tool_class",
            ]);
            return '';
        }

        if (isset($seenClasses[$toolClass])) {
            $result->addError([
                'code'     => 'TOOL_CLASS_DUPLICATE',
                'severity' => 'error',
                'message'  => sprintf("Duplicate tool_class '%s'.", $toolClass),
                'path'     => "{$path}.tool_class",
            ]);
        }
        $seenClasses[$toolClass] = true;
        return $toolClass;
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function validateToolEnabledFlag(array $tool, string $path, ValidationResult $result): void
    {
        if (!array_key_exists('enabled', $tool) || !is_bool($tool['enabled'])) {
            $result->addError([
                'code'     => 'TOOL_ENABLED_REQUIRED',
                'severity' => 'error',
                'message'  => "Tool entry is missing boolean 'enabled'.",
                'path'     => "{$path}.enabled",
            ]);
        }
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function validateToolOperations(array $tool, string $toolClass, string $path, ValidationResult $result): void
    {
        $operations = $tool['operations'] ?? null;
        if (!is_array($operations) || !array_is_list($operations)) {
            $result->addError([
                'code'     => 'OPERATIONS_NOT_LIST',
                'severity' => 'error',
                'message'  => "Field 'operations' must be an array.",
                'path'     => "{$path}.operations",
            ]);
            return;
        }

        $knownOps = $toolClass !== '' ? $this->knownOperationNames($toolClass) : null;
        foreach ($operations as $opIndex => $op) {
            $this->validateOperationEntry($op, $opIndex, $path, $toolClass, $knownOps, $result);
        }
    }

    /**
     * @param list<string>|null $knownOps
     */
    private function validateOperationEntry(
        mixed $op,
        int $opIndex,
        string $parentPath,
        string $toolClass,
        ?array $knownOps,
        ValidationResult $result,
    ): void {
        $opPath = "{$parentPath}.operations[{$opIndex}]";

        if (!is_array($op)) {
            $result->addError([
                'code'     => 'OPERATION_NOT_OBJECT',
                'severity' => 'error',
                'message'  => "Operation entry must be an object.",
                'path'     => $opPath,
            ]);
            return;
        }

        $opName = $this->validateOperationName($op, $opPath, $result);
        if ($opName === '') {
            return;
        }

        $this->validateOperationAgainstKnownOps($opName, $toolClass, $knownOps, $opPath, $result);
        $this->validateOperationBooleanField($op, 'auto_approve', $opPath, $result);
        $this->validateOperationBooleanField($op, 'enabled', $opPath, $result);
    }

    /**
     * @param array<string, mixed> $op
     */
    private function validateOperationName(array $op, string $opPath, ValidationResult $result): string
    {
        $opName = $op['name'] ?? null;
        if (!is_string($opName) || $opName === '') {
            $result->addError([
                'code'     => 'OPERATION_NAME_REQUIRED',
                'severity' => 'error',
                'message'  => "Operation entry is missing 'name'.",
                'path'     => "{$opPath}.name",
            ]);
            return '';
        }
        return $opName;
    }

    /**
     * @param list<string>|null $knownOps
     */
    private function validateOperationAgainstKnownOps(
        string $opName,
        string $toolClass,
        ?array $knownOps,
        string $opPath,
        ValidationResult $result,
    ): void {
        if ($knownOps === null || $knownOps === [] || in_array($opName, $knownOps, true)) {
            return;
        }
        $result->addWarning([
            'code'     => 'OPERATION_UNKNOWN',
            'severity' => 'warning',
            'message'  => sprintf(
                "Operation '%s' is not declared by tool '%s'. Import will be skipped.",
                $opName,
                $toolClass,
            ),
            'path'     => "{$opPath}.name",
        ]);
    }

    /**
     * @param array<string, mixed> $op
     */
    private function validateOperationBooleanField(array $op, string $field, string $opPath, ValidationResult $result): void
    {
        if (!array_key_exists($field, $op)) {
            return;
        }
        if (!is_bool($op[$field])) {
            $result->addError([
                'code'     => strtoupper($field) . '_TYPE',
                'severity' => 'error',
                'message'  => sprintf("Field '%s' must be a boolean.", $field),
                'path'     => "{$opPath}.{$field}",
            ]);
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validateRequiredPlugins(array $raw, ValidationResult $result): void
    {
        if (!array_key_exists('required_plugins', $raw)) {
            return;
        }
        $slugs = $raw['required_plugins'];
        if (!is_array($slugs) || !array_is_list($slugs)) {
            $result->addError([
                'code'     => 'REQUIRED_PLUGINS_NOT_LIST',
                'severity' => 'error',
                'message'  => "Field 'required_plugins' must be an array of strings.",
                'path'     => 'required_plugins',
            ]);
            return;
        }
        foreach ($slugs as $index => $slug) {
            if (!is_string($slug) || !preg_match(self::SLUG_PATTERN, $slug)) {
                $result->addError([
                    'code'     => 'REQUIRED_PLUGINS_INVALID',
                    'severity' => 'error',
                    'message'  => "Each entry in 'required_plugins' must match the plugin slug pattern.",
                    'path'     => "required_plugins[{$index}]",
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validateMetadata(array $raw, ValidationResult $result): void
    {
        if (!array_key_exists('metadata', $raw)) {
            return;
        }
        $metadata = $raw['metadata'];
        if (!is_array($metadata)) {
            $result->addError([
                'code'     => 'METADATA_NOT_OBJECT',
                'severity' => 'error',
                'message'  => "Field 'metadata' must be an object.",
                'path'     => 'metadata',
            ]);
            return;
        }
        foreach (array_keys($metadata) as $key) {
            if (in_array($key, self::ALLOWED_METADATA_KEYS, true)) {
                continue;
            }
            $result->addError([
                'code'     => 'UNKNOWN_METADATA_KEY',
                'severity' => 'error',
                'message'  => sprintf("Unknown field 'metadata.%s'.", $key),
                'path'     => 'metadata.' . $key,
            ]);
        }
        if (isset($metadata['category']) && !in_array($metadata['category'], self::ALLOWED_CATEGORIES, true)) {
            $result->addWarning([
                'code'     => 'METADATA_CATEGORY_UNKNOWN',
                'severity' => 'warning',
                'message'  => sprintf(
                    "Unknown category '%s'. Expected one of: %s.",
                    (string) $metadata['category'],
                    implode(', ', self::ALLOWED_CATEGORIES),
                ),
                'path'     => 'metadata.category',
            ]);
        }
        if (isset($metadata['icon']) && !is_string($metadata['icon'])) {
            $result->addError([
                'code'     => 'METADATA_ICON_TYPE',
                'severity' => 'error',
                'message'  => "Field 'metadata.icon' must be a string.",
                'path'     => 'metadata.icon',
            ]);
        }
    }

    /**
     * Return the operation names a given tool class declares via
     * #[ToolOperation] attributes. Null if the class is not loadable
     * — the importer will surface a separate TOOL_PLUGIN_MISSING warning.
     *
     * @return list<string>|null
     */
    private function knownOperationNames(string $toolClass): ?array
    {
        if (!class_exists($toolClass)) {
            return null;
        }
        if (!is_subclass_of($toolClass, ToolInterface::class)) {
            return null;
        }
        $names = [];
        $reflection = new ReflectionClass($toolClass);
        foreach ($reflection->getAttributes(ToolOperation::class) as $attr) {
            $instance = $attr->newInstance();
            $names[] = $instance->name;
        }
        return $names;
    }
}
