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
     * Shared partial-patch pipeline. `allowScheduleRecurrenceSwitch`
     * forbids mutating a schedule from one-shot ↔ recurring in one
     * patch (the service reconciles `cron_expression` ↔ `run_at`
     * internally on a single field, so flipping both at once is
     * ambiguous).
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

        if ($isSchedule) {
            $hasCron = array_key_exists('cron_expression', $raw);
            $hasRunAt = array_key_exists('run_at', $raw);
            if ($hasCron && $hasRunAt && $raw['cron_expression'] !== null && $raw['run_at'] !== null) {
                return ToolResult::fail(
                    self::OP_UPDATE_SCHEDULE . ': `cron_expression` and `run_at` are mutually exclusive. '
                    . 'Send exactly one — `null` the other to switch modes.',
                );
            }
            if (isset($raw['timezone'])) {
                $tzError = $this->validateTimezone($raw['timezone']);
                if ($tzError !== null) {
                    return $tzError;
                }
            }
            if (array_key_exists('cron_expression', $raw) && $raw['cron_expression'] !== null) {
                $cronError = $this->validateCronExpression($raw['cron_expression']);
                if ($cronError !== null) {
                    return $cronError;
                }
            }
            if (array_key_exists('run_at', $raw) && $raw['run_at'] !== null) {
                $runAtError = $this->validateRunAt(
                    $raw['run_at'],
                    is_string($raw['timezone'] ?? null) ? $raw['timezone'] : 'UTC',
                );
                if ($runAtError !== null) {
                    return $runAtError;
                }
            }
        }

        if (isset($raw['is_active']) && !is_bool($raw['is_active'])) {
            return ToolResult::fail(
                $op . ': `is_active` must be a boolean.',
            );
        }

        if (array_key_exists('max_steps_override', $raw) && $raw['max_steps_override'] !== null) {
            $value = $raw['max_steps_override'];
            if (!is_int($value) || $value < 1 || $value > 100) {
                return ToolResult::fail(
                    self::OP_UPDATE_SCHEDULE . ': `max_steps_override` must be an integer in 1..100, or null to clear it.',
                );
            }
        }

        if (array_key_exists('template_id', $raw) && $raw['template_id'] !== null && !is_int($raw['template_id'])) {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `template_id` must be a positive integer, or null to clear it.',
            );
        }

        if (array_key_exists('max_steps', $raw) && $raw['max_steps'] !== null) {
            $value = $raw['max_steps'];
            if (!is_int($value) || $value < 1 || $value > 100) {
                return ToolResult::fail(
                    self::OP_UPDATE_TEMPLATE . ': `max_steps` must be an integer in 1..100, or null to clear it.',
                );
            }
        }

        if (array_key_exists('name', $raw)) {
            if (!is_string($raw['name']) || trim($raw['name']) === '' || mb_strlen($raw['name']) > 100) {
                return ToolResult::fail(
                    self::OP_UPDATE_TEMPLATE . ': `name` must be a non-empty string (1..100 chars).',
                );
            }
        }

        if (array_key_exists('variables', $raw) && $raw['variables'] !== null) {
            $variablesError = $this->validateVariables($raw['variables']);
            if ($variablesError !== null) {
                return $variablesError;
            }
        }

        return $raw;
    }

    private function validateTimezone(mixed $value): ?ToolResult
    {
        if (!is_string($value)) {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `timezone` must be a string (IANA identifier).',
            );
        }
        if (strlen($value) > 50) {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `timezone` must not exceed 50 characters.',
            );
        }
        if (!in_array($value, timezone_identifiers_list(), true)) {
            return ToolResult::fail(
                self::OP_UPDATE_SCHEDULE . ': `timezone` must be a valid IANA identifier.',
            );
        }
        return null;
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
