<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Tools\LlmScalarCoercion;
use Spora\Tools\ToolSchemaPresenter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The operations half of a `configure_tools` entry.
 *
 * Split from {@see ConfigurePlanParser}: a row is checked against the operation
 * names the tool class actually declares, a lookup no other half of the entry
 * needs. It also owns the tri-state flag rule a row's `enabled` /
 * `auto_approve` are read through — the same rule the entry's own `enabled` is,
 * which is why that one method is public.
 */
final class ConfigurePlanOperationsParser
{
    use LlmScalarCoercion;

    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';

    /**
     * Empty / missing operations is legal — the operation default then
     * applies.
     *
     * A non-array is *refused*, not dropped, which is what this class does
     * everywhere else and what makes the sibling `settings` check consistent:
     * `{"operations": "now"}` silently returning "no operations" lets the
     * whole call report success having applied nothing, and the model reads
     * that as an enablement that landed. Absent, `null` and `[]` all still
     * mean "inherit the operation defaults"; only a value that claims to be
     * an operations list and is not one is an error.
     *
     * @param  mixed $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    public function parseOperations(mixed $ops, string $toolClass, int $i): array|ToolResult
    {
        if ($ops === null || $ops === []) {
            return [];
        }
        // `unwrapSingleItemArray()` returns a non-array unchanged and only ever
        // substitutes an array it checked with `is_array()`, so the two refusals
        // below are one condition: whatever we were handed, and whatever it
        // unwrapped to, either claims to be an operations list or it is an error.
        $ops = is_array($ops) ? SlimPayloadValidator::unwrapSingleItemArray($ops) : $ops;
        if (!is_array($ops) || ($ops !== [] && !array_is_list($ops))) {
            return $this->operationsFailure($i);
        }
        return $this->parseOperationRows($ops, $toolClass, $i);
    }

    /**
     * @param  list<mixed> $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseOperationRows(array $ops, string $toolClass, int $i): array|ToolResult
    {
        $declared = self::declaredOperationNames($toolClass);
        $out = [];
        foreach ($ops as $j => $op) {
            $row = $this->parseOperationRow($op, $declared, $toolClass, "operations[{$i}][{$j}]");
            if ($row instanceof ToolResult) {
                return $row;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param  array<string, true> $declared
     * @return array{name: string, enabled: bool, auto_approve: bool}|ToolResult
     */
    private function parseOperationRow(mixed $op, array $declared, string $toolClass, string $at): array|ToolResult
    {
        $name = $this->operationName($op, $declared, $toolClass, $at);
        if ($name instanceof ToolResult) {
            return $name;
        }

        $flags = $this->operationFlags($op, $at);
        if ($flags instanceof ToolResult) {
            return $flags;
        }

        return ['name' => $name, 'enabled' => $flags['enabled'], 'auto_approve' => $flags['auto_approve']];
    }

    /**
     * The operation's name, once it is known to be a non-empty string that the
     * tool actually declares.
     *
     * The declaration check is skipped for a class that declares no operations
     * or cannot be reflected against — a plugin tool that is not loaded is not
     * grounds for refusing every name it might legitimately declare.
     *
     * @param  array<string, true> $declared
     * @return string|ToolResult
     */
    private function operationName(mixed $op, array $declared, string $toolClass, string $at): string|ToolResult
    {
        $name = is_array($op) && is_string($op['name'] ?? null) ? $op['name'] : '';
        if ($name === '') {
            return ToolResult::fail(
                self::CONFIGURE_TOOLS_ERR_PREFIX . "{$at} must be `{name, enabled?, auto_approve?}`.",
            );
        }
        if ($declared !== [] && !isset($declared[$name])) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . sprintf(
                "%s names '%s', which is not an operation on %s. Available operations: %s.",
                $at,
                $name,
                $toolClass,
                implode(', ', array_keys($declared)),
            ));
        }

        return $name;
    }

    /**
     * @param  array<string, mixed> $op
     * @return array{enabled: bool, auto_approve: bool}|ToolResult
     */
    private function operationFlags(array $op, string $at): array|ToolResult
    {
        $enabled = $this->enablementFlag($op, 'enabled', $at) ?? true;
        if ($enabled instanceof ToolResult) {
            return $enabled;
        }
        $autoApprove = $this->enablementFlag($op, 'auto_approve', $at) ?? false;
        if ($autoApprove instanceof ToolResult) {
            return $autoApprove;
        }

        return ['enabled' => $enabled, 'auto_approve' => $autoApprove];
    }

    /**
     * The operation names a tool class actually declares, keyed for lookup.
     *
     * Empty when the class declares no operations or cannot be reflected
     * against. Callers must then skip validation rather than refuse every
     * name — a plugin tool that is not loaded is not grounds for a
     * false refusal.
     *
     * @return array<string, true>
     */
    private static function declaredOperationNames(string $toolClass): array
    {
        $names = [];
        foreach (ToolSchemaPresenter::summarize($toolClass)['operations'] as $op) {
            if ($op['name'] !== '') {
                $names[$op['name']] = true;
            }
        }
        return $names;
    }

    /**
     * A tri-state enablement flag: `true`, `false`, or null when the key is
     * absent and enablement should be left alone.
     *
     * Delegates to {@see LlmScalarCoercion::coerceBool()} so every tool
     * accepts the same set of scalar spellings — some providers quote their
     * booleans, and a surface that refused those would let a model grant a
     * tool but never revoke one. Nothing here is cast: the `(bool)` cast
     * this replaced read `"false"` as **true**, so a quoted revocation
     * enabled the tool it was meant to remove.
     *
     * One local rule on top. A blank string is treated as *absent* rather
     * than as `false`, unlike the shared helper. Tri-state makes "no
     * change" representable here, so a malformed empty value should not be
     * read as a revocation — where there is no tri-state (a plain boolean
     * setting) the shared helper's `"empty means false"` stands.
     *
     * @param  array<string, mixed> $entry
     * @return bool|null|ToolResult
     */
    public function enablementFlag(array $entry, string $key, string $at): bool|null|ToolResult
    {
        $raw = $entry[$key] ?? null;
        if (!array_key_exists($key, $entry) || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $coerced = $this->coerceBool($raw);

        if ($coerced !== null) {
            return $coerced;
        }

        return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . sprintf(
            "%s '%s' must be true or false, got %s. Send true / false, or the string "
            . '"true" / "false" — a quoted value is read as the boolean it names, not as truthy.',
            $at,
            $key,
            $this->describeValue($raw),
        ));
    }

    /**
     * Mirrors the settings half's refusal shape, so a refusal on either half
     * of an entry reads the same way to the model.
     */
    private function operationsFailure(int $i): ToolResult
    {
        return ToolResult::fail(
            self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}] must be an array of `{name, enabled?, auto_approve?}`.",
        );
    }
}
