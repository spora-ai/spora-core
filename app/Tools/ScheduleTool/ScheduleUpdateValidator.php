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
        // LLM wire-shape leniency: treat the four-character string "null"
        // (and "" / "NULL" / "Null" / empty) as JSON null for clearable
        // fields. Providers like OpenAI encode tool-call `arguments` as a
        // JSON string, so a model that emits `"cron_expression":"null"`
        // intending null arrives at the validator as the literal PHP
        // string "null" — strict rejection produced the misleading "send
        // JSON null, not the string" hint that the Round 5 bug report
        // flagged. Normalising BEFORE the field-type checks lets the
        // existing null-aware paths (`$r['x'] !== null` short-circuits,
        // `array_key_exists()` for the cron / run_at / template_id /
        // max_steps_override / max_steps intent) treat these as real
        // clears without changing the contract for genuinely-invalid
        // strings.
        $raw = $this->normalizeNullLikeStrings($raw, $op);

        if ($isSchedule) {
            $scheduleError = $this->validatePartialScheduleFields($raw);
            if ($scheduleError !== null) {
                return $scheduleError;
            }
        }

        return $this->validatePartialSharedFields($raw) ?? $raw;
    }

    /**
     * Coerce the literal string "null" (case-insensitive) and the empty
     * string to PHP null for every clearable patch field. The five fields
     * listed in the Round 5 bug report — `cron_expression`, `run_at`,
     * `template_id`, `max_steps_override` on update_schedule and
     * `max_steps` on update_prompt_template — are the only ones touched;
     * timezone intentionally is not normalised here because empty-string
     * timezone already fails the IANA identifier check in the right way
     * (it's not a clear-the-field contract).
     *
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeNullLikeStrings(array $raw, string $op): array
    {
        $fields = $op === self::OP_UPDATE_TEMPLATE
            ? ['max_steps']
            : ['cron_expression', 'run_at', 'template_id', 'max_steps_override'];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $value = $raw[$field];
            if (is_string($value) && in_array(trim($value), ['', 'null', 'NULL', 'Null'], true)) {
                $raw[$field] = null;
            }
        }

        return $raw;
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
                    self::OP_UPDATE_SCHEDULE . ': `is_active` must be a boolean — '
                    . 'send JSON `true` / `false` (not the strings "true" / "false"). '
                    . 'Got ' . $this->describeValue($r['is_active']) . '.',
                )
                : null,
            fn(array $r) => (
                array_key_exists('max_steps_override', $r)
                && $r['max_steps_override'] !== null
            )
                ? $this->checkClearableIntRange(
                    $r['max_steps_override'],
                    self::OP_UPDATE_SCHEDULE,
                    'max_steps_override',
                )
                : null,
            fn(array $r) => (
                array_key_exists('template_id', $r)
                && $r['template_id'] !== null
                && $this->coercePositiveInt($r['template_id']) === null
            )
                ? ToolResult::fail(
                    self::OP_UPDATE_SCHEDULE . ': `template_id` must be a positive integer referencing an existing '
                    . 'prompt template, or JSON `null` to unbind the template (the schedule will fall back to its '
                    . 'raw prompt). The four-character string "null" is NOT the same as JSON null and cannot be used '
                    . 'to clear the field. Got ' . $this->describeValue($r['template_id']) . '.',
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
                ? $this->checkClearableIntRange(
                    $r['max_steps'],
                    self::OP_UPDATE_TEMPLATE,
                    'max_steps',
                )
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
     * Range-check an integer field whose null semantics are documented as a
     * "clear the value" sentinel. The error message distinguishes the JSON
     * `null` contract from the literal string "null" because prior reports
     * showed the bare four-character word confused callers into believing
     * the server was rejecting JSON null.
     *
     * @param mixed $value
     */
    private function checkClearableIntRange(mixed $value, string $op, string $field): ?ToolResult
    {
        $int = $this->coercePositiveInt($value);
        if ($int === null) {
            return ToolResult::fail(
                $op . ': `' . $field . '` must be an integer in 1..100, or JSON `null` to clear the field. '
                . 'The four-character string "null" is NOT a valid value here — send the JSON null literal, '
                . 'not the string "null". Got ' . $this->describeValue($value) . '.',
            );
        }

        if ($int < 1 || $int > 100) {
            return ToolResult::fail(
                $op . ': `' . $field . '` must be between 1 and 100 (got ' . $int . ').',
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
        // Surface a clear "send JSON null, not the string" hint when the
        // caller submitted the literal four-character string "null".
        // Without this, CronExpression("null") throws and we bubble up
        // a generic "invalid syntax" error that buries the real cause.
        if (!is_string($cron) || in_array(trim($cron), ['', 'null', 'NULL', 'Null'], true)) {
            return $this->cronShapeError($cron);
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

    /**
     * Companion to {@see validateCronExpression()} — keeps the parent
     * under Sonar's 3-return limit by emitting the "send JSON null"
     * hint and the "must be a string" diagnostic from a single helper.
     */
    private function cronShapeError(mixed $cron): ToolResult
    {
        $gotString = in_array(trim(is_string($cron) ? $cron : ''), ['', 'null', 'NULL', 'Null'], true);

        $hint = $gotString
            ? ' Got the string ' . $this->describeValue($cron) . ' — the JSON null literal is not a string. '
              . 'To clear the cron field, send the JSON null value, not the string "null".'
            : '';

        return ToolResult::fail(
            self::OP_UPDATE_SCHEDULE . ': `cron_expression` must be a non-empty 5-field cron string, or JSON '
            . '`null` to clear the schedule (switch to one-shot mode). '
            . 'Got ' . $this->describeValue($cron) . '.' . $hint,
        );
    }

    private function validateRunAt(mixed $runAt, string $timezone): ?ToolResult
    {
        if (!is_string($runAt) || in_array(trim($runAt), ['', 'null', 'NULL', 'Null'], true)) {
            return $this->runAtShapeError($runAt);
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

    /**
     * Companion to {@see validateRunAt()} — same role as
     * {@see cronShapeError()}, kept separate so each validator stays
     * under Sonar's 3-return limit while preserving distinct wording.
     */
    private function runAtShapeError(mixed $runAt): ToolResult
    {
        $gotString = in_array(trim(is_string($runAt) ? $runAt : ''), ['', 'null', 'NULL', 'Null'], true);

        $hint = $gotString
            ? ' Got the string ' . $this->describeValue($runAt) . ' — the JSON null literal is not a string. '
              . 'To clear the run_at field, send the JSON null value, not the string "null".'
            : '';

        return ToolResult::fail(
            self::OP_UPDATE_SCHEDULE . ': `run_at` must be a non-empty ISO 8601 string, or JSON `null` to clear '
            . 'the schedule (switch to recurring mode). Got ' . $this->describeValue($runAt) . '.' . $hint,
        );
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
