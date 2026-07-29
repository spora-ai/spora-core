<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Per-operation validation + apply for `configure_tools`. Extracted
 * from AgentTool so the tool class stays under SonarCloud S1448's
 * 20-method ceiling.
 *
 * Flow:
 *   1. `buildConfigureToolsPlan` walks each `tools[i]` entry once.
 *   2. `parseConfigureToolEntry` validates the entry's shape.
 *   3. `parseConfigureToolOperations` validates the entry's operations
 *      (defensively `unwrapSingleItemArray`-ing the `{item: […]}` quirk).
 *   4. `applyConfigureToolsPlan` writes each plan step through
 *      `AgentToolSettingsServiceInterface` — the existing operator-side
 *      surface — so the LLM-facing path and the operator-facing API
 *      share the same enable / override semantics.
 */
final class ConfigurePlanner
{
    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';

    public function __construct(
        private readonly AgentToolSettingsServiceInterface $toolSettings,
    ) {}

    /**
     * @param  mixed $entries
     * @return list<array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}>|ToolResult
     */
    public function buildPlan(mixed $entries): array|ToolResult
    {
        $plan = [];
        foreach ($entries as $i => $entry) {
            $step = $this->parseEntry($entry, $i);
            if ($step instanceof ToolResult) {
                return $step;
            }
            $plan[] = $step;
        }
        return $plan;
    }

    /**
     * Apply the validated `configure_tools` plan.
     *
     * @param  list<array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}> $plan
     */
    public function apply(int $agentId, int $userId, array $plan): void
    {
        foreach ($plan as $step) {
            if ($step['enable']) {
                $this->toolSettings->enableTool($agentId, $userId, $step['tool_class']);
            } else {
                $this->toolSettings->disableTool($agentId, $userId, $step['tool_class']);
            }
            foreach ($step['operations'] as $op) {
                $this->toolSettings->patchOperationOverride(
                    $agentId,
                    $userId,
                    $step['tool_class'],
                    $op['name'],
                    [
                        'enabled'                   => $op['enabled'] ? 1 : 0,
                        'default_requires_approval' => $op['auto_approve'] ? 0 : 1,
                    ],
                );
            }
        }
    }

    /**
     * @param  mixed $entry
     * @return array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>}|ToolResult
     */
    private function parseEntry(mixed $entry, int $i): array|ToolResult
    {
        $shapeFail = $this->shapeEntryFailure($entry, $i);
        if ($shapeFail !== null) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . $shapeFail);
        }
        $toolClass = (string) ($entry['tool_class'] ?? '');

        $operations = $this->parseOperations($entry['operations'] ?? [], $i);
        if ($operations instanceof ToolResult) {
            return $operations;
        }
        return [
            'tool_class' => $toolClass,
            'enable'     => (bool) ($entry['enabled'] ?? true),
            'operations' => $operations,
        ];
    }

    private function shapeEntryFailure(mixed $entry, int $i): ?string
    {
        if (!is_array($entry)) {
            return "tool entry #{$i} must be an object.";
        }
        if (!isset($entry['tool_class']) || !is_string($entry['tool_class']) || $entry['tool_class'] === '') {
            return "tool entry #{$i} is missing `tool_class`.";
        }
        return null;
    }

    /**
     * Empty / missing operations is legal — the operation default then
     * applies.
     *
     * @param  mixed $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseOperations(mixed $ops, int $i): array|ToolResult
    {
        if (!is_array($ops) || $ops === []) {
            return [];
        }
        $ops = SlimPayloadValidator::unwrapSingleItemArray($ops);
        if (!is_array($ops) || ($ops !== [] && !array_is_list($ops))) {
            return ToolResult::fail(
                self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}] must be an array of `{name, enabled?, auto_approve?}`.",
            );
        }
        return $this->parseOperationRows($ops, $i);
    }

    /**
     * @param  list<mixed> $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseOperationRows(array $ops, int $i): array|ToolResult
    {
        $out = [];
        foreach ($ops as $j => $op) {
            if (!is_array($op) || !isset($op['name']) || !is_string($op['name']) || $op['name'] === '') {
                return ToolResult::fail(
                    self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}][{$j}] must be `{name, enabled?, auto_approve?}`.",
                );
            }
            $out[] = [
                'name'         => $op['name'],
                'enabled'      => (bool) ($op['enabled'] ?? true),
                'auto_approve' => (bool) ($op['auto_approve'] ?? false),
            ];
        }
        return $out;
    }
}
