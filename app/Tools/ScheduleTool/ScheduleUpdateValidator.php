<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Validates the partial `update_schedule` + `update_prompt_template`
 * patches. Strict allowlists keep the LLM from silently expanding the
 * service's column surface, and unknown keys surface a literal
 * "send X instead" hint per the AgentTool precedent.
 */
final class ScheduleUpdateValidator
{
    use SchedulableTypeCoercion;

    public const OP_UPDATE_SCHEDULE  = 'update_schedule';
    public const OP_UPDATE_TEMPLATE = 'update_prompt_template';

    public const SCHEDULE_PATCH_KEYS = [
        'template_id',
        'raw_prompt',
        'cron_expression',
        'run_at',
        'timezone',
        'max_steps_override',
        'is_active',
    ];

    public const TEMPLATE_PATCH_KEYS = [
        'name',
        'description',
        'prompt_template',
        'variables',
        'max_steps',
        'is_active',
    ];

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public function validateUpdateSchedulePatch(array $arguments): array|ToolResult
    {
        return $this->validatePartial(
            $arguments['schedule_patch'] ?? null,
            self::OP_UPDATE_SCHEDULE,
            self::SCHEDULE_PATCH_KEYS,
            true,
        );
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public function validateUpdateTemplatePatch(array $arguments): array|ToolResult
    {
        return $this->validatePartial(
            $arguments['template_patch'] ?? null,
            self::OP_UPDATE_TEMPLATE,
            self::TEMPLATE_PATCH_KEYS,
            false,
        );
    }

    /**
     * Shared partial-patch pipeline. Splits into a small chain so each
     * gate owns one concern (shape → schedule-specific fields →
     * shared type-coercion), keeping cognitive complexity manageable.
     *
     * @param  mixed $raw
     * @return array<string, mixed>|ToolResult
     */
    private function validatePartial(
        mixed $raw,
        string $op,
        array $allowed,
        bool $isSchedule,
    ): array|ToolResult {
        $shapeError = $this->validatePartialShape($raw, $op, $allowed);
        if ($shapeError !== null) {
            return $shapeError;
        }

        /** @var array<string, mixed> $raw */
        if ($isSchedule) {
            $scheduleError = $this->validatePartialScheduleFields($raw);
            if ($scheduleError !== null) {
                return $scheduleError;
            }
        }

        return $this->validatePartialSharedFields($raw) ?? $raw;
    }

    /**
     * Reject missing or unknown keys; gate every patch on a non-empty
     * object whose keys are in the per-operation allowlist.
     *
     * @param  mixed  $raw
     * @param  string $op
     * @param  array<int, string> $allowed
     */
    private function validatePartialShape(mixed $raw, string $op, array $allowed): ?ToolResult
    {
        if (!is_array($raw) || $raw === []) {
            return ToolResult::fail(
                $op . ': patch object is required with at least one mutable key. '
                . 'Allowed keys: ' . implode(', ', $allowed) . '.',
            );
        }

        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                return ToolResult::fail(
                    $op . ': `' . $key . '` is not a mutable key. '
                    . 'Allowed keys: ' . implode(', ', $allowed) . '.',
                );
            }
        }

        return null;
    }

    /**
     * Schedule-only gates: mutually-exclusive cron_expression ↔ run_at,
     * plus type checks on `timezone` / cron / run_at.
     *
     * @param array<string, mixed> $raw
     */
    private function validatePartialScheduleFields(array $raw): ?ToolResult
    {
        $error = $this->guardPartialCadenceExclusivity($raw);
        if ($error !== null) {
            return $error;
        }

        $error = $this->guardPartialTimezoneField($raw);
        if ($error !== null) {
            return $error;
        }

        return $this->guardPartialCadenceFields($raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function guardPartialCadenceExclusivity(array $raw): ?ToolResult
    {
        $hasCron  = array_key_exists('cron_expression', $raw);
        $hasRunAt = array_key_exists('run_at', $raw);
        $cronVal  = $raw['cron_expression'] ?? null;
        $runAtVal = $raw['run_at'] ?? null;

        if ($hasCron && $hasRunAt && $cronVal !== null && $runAtVal !== null) {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `cron_expression` and `run_at` are mutually exclusive. '
                . 'Send exactly one — `null` the other to switch modes.',
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function guardPartialTimezoneField(array $raw): ?ToolResult
    {
        $tzValue = $raw['timezone'] ?? null;
        if ($tzValue === null) {
            return null;
        }

        return $this->validateTimezone($tzValue);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function guardPartialCadenceFields(array $raw): ?ToolResult
    {
        $cronVal  = $raw['cron_expression'] ?? null;
        $runAtVal = $raw['run_at'] ?? null;

        if ($cronVal !== null) {
            return $this->validateCronExpression($cronVal);
        }

        if ($runAtVal !== null) {
            return $this->validateRunAt(
                $runAtVal,
                is_string($raw['timezone'] ?? null) ? $raw['timezone'] : 'UTC',
            );
        }

        return null;
    }

    /**
     * Cross-cutting type checks for fields that appear on both
     * operations: `is_active`, `max_steps_override`, `template_id`,
     * `max_steps`, `name`, `variables`. Split into per-field helpers
     * to keep cognitive complexity manageable.
     *
     * @param array<string, mixed> $raw
     */
    private function validatePartialSharedFields(array $raw): ?ToolResult
    {
        $checks = [
            fn(array $r) => $this->validatePartialSharedScheduleFields($r),
            fn(array $r) => $this->validatePartialSharedTemplateFields($r),
        ];

        return $this->firstFailure($raw, $checks);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validatePartialSharedScheduleFields(array $raw): ?ToolResult
    {
        $checks = [
            fn(array $r) => isset($r['is_active']) && $this->coerceBool($r['is_active']) === null
                ? ToolResult::fail(
                    self::OP_UPDATE_SCHEDULE . ': `is_active` must be a boolean (got '
                    . $this->describeValue($r['is_active']) . ').',
                )
                : null,
            fn(array $r) => (
                array_key_exists('max_steps_override', $r)
                && $r['max_steps_override'] !== null
            )
                ? $this->checkIntRange(
                    $r['max_steps_override'],
                    self::OP_UPDATE_SCHEDULE,
                    '`max_steps_override`',
                )
                : null,
            fn(array $r) => (
                array_key_exists('template_id', $r)
                && $r['template_id'] !== null
                && $this->coercePositiveInt($r['template_id']) === null
            )
                ? ToolResult::fail(
                    self::OP_UPDATE_SCHEDULE . ': `template_id` must be a positive integer, or null to clear it (got '
                    . $this->describeValue($r['template_id']) . ').',
                )
                : null,
        ];

        return $this->firstFailure($raw, $checks);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validatePartialSharedTemplateFields(array $raw): ?ToolResult
    {
        $checks = [
            fn(array $r) => (
                array_key_exists('max_steps', $r)
                && $r['max_steps'] !== null
            )
                ? $this->checkIntRange($r['max_steps'], self::OP_UPDATE_TEMPLATE, '`max_steps`')
                : null,
            fn(array $r) => (
                array_key_exists('name', $r)
                && (
                    !is_string($r['name'])
                    || trim($r['name']) === ''
                    || mb_strlen($r['name']) > 100
                )
            )
                ? ToolResult::fail(
                    self::OP_UPDATE_TEMPLATE . ': `name` must be a non-empty string (1..100 chars).',
                )
                : null,
            fn(array $r) => (
                array_key_exists('variables', $r)
                && $r['variables'] !== null
            )
                ? $this->validateVariables($r['variables'])
                : null,
        ];

        return $this->firstFailure($raw, $checks);
    }

    /**
     * Run a sequence of check callables against `$raw` and return the
     * first non-null ToolResult. Lets callers express a chain of
     * validators without each contributing a `return`.
     *
     * @template T
     * @param  T                                          $raw
     * @param  array<int, callable(T):?ToolResult>        $checks
     */
    private function firstFailure(mixed $raw, array $checks): ?ToolResult
    {
        foreach ($checks as $check) {
            $result = $check($raw);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function checkIntRange(mixed $value, string $op, string $fieldLabel): ?ToolResult
    {
        $int = $this->coercePositiveInt($value);
        if ($int === null) {
            return ToolResult::fail(
                $op . ': ' . $fieldLabel . ' must be an integer in 1..100, or null to clear it (got '
                . $this->describeValue($value) . ').',
            );
        }

        if ($int < 1 || $int > 100) {
            return ToolResult::fail(
                $op . ': ' . $fieldLabel . ' must be between 1 and 100 (got ' . $int . ').',
            );
        }

        return null;
    }

    private function validateTimezone(mixed $value): ?ToolResult
    {
        $error = null;
        if (!is_string($value)) {
            $error = ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `timezone` must be a string (IANA identifier).',
            );
        } elseif (strlen($value) > 50) {
            $error = ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `timezone` must not exceed 50 characters.',
            );
        } elseif (!in_array($value, timezone_identifiers_list(), true)) {
            $error = ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `timezone` must be a valid IANA identifier.',
            );
        }

        return $error;
    }

    private function validateCronExpression(mixed $cron): ?ToolResult
    {
        if (!is_string($cron) || trim($cron) === '') {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `cron_expression` must be a non-empty string, or null to clear it.',
            );
        }
        try {
            new CronExpression($cron);
        } catch (Throwable) {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `cron_expression` is invalid. Use 5-field cron syntax.',
            );
        }
        return null;
    }

    private function validateRunAt(mixed $runAt, string $timezone): ?ToolResult
    {
        if (!is_string($runAt) || trim($runAt) === '') {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `run_at` must be a non-empty ISO 8601 string, or null to clear it.',
            );
        }
        try {
            new DateTimeImmutable($runAt, new DateTimeZone($timezone));
        } catch (Throwable) {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `run_at` must be a valid ISO 8601 datetime parseable in `timezone` "' . $timezone . '".',
            );
        }
        return null;
    }

    private function validateVariables(mixed $value): ?ToolResult
    {
        if (!is_array($value)) {
            return ToolResult::fail(
                self::OP_UPDATE_TEMPLATE . ': `variables` must be an array of `{key, default_value?}` entries.',
            );
        }

        foreach ($value as $i => $entry) {
            if (!is_array($entry) || !isset($entry['key']) || !is_string($entry['key']) || $entry['key'] === '') {
                return ToolResult::fail(
                    self::OP_UPDATE_TEMPLATE . ": variables[{$i}] must be `{key, default_value?}`. Send the variable key as a non-empty string.",
                );
            }
        }

        return null;
    }
}
