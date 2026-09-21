<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Validates the slim `create_schedule` + `create_prompt_template`
 * payloads that the LLM sends to `ScheduleTool`.
 *
 * Mirrors `AgentTool\SlimPayloadValidator`: rejects the operator-upload
 * shape, returns the canonical slim record the services expect, and
 * every failure message ends with a "send X instead" hint so the LLM
 * can fix the payload without guessing.
 */
final class SchedulePayloadValidator
{
    public const OP_CREATE_SCHEDULE  = 'create_schedule';
    public const OP_CREATE_TEMPLATE = 'create_prompt_template';

    public const TEMPLATE_NAME_MAX = 100;
    public const TIMEZONE_MAX      = 50;

    public const SCHEDULE_KNOWN_KEYS = [
        'template_id',
        'raw_prompt',
        'cron_expression',
        'run_at',
        'timezone',
        'max_steps_override',
        'is_active',
    ];

    public const TEMPLATE_KNOWN_KEYS = [
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
    public function validateCreateSchedule(array $arguments): array|ToolResult
    {
        $raw = $arguments['schedule_payload'] ?? null;
        $shape = $this->scheduleShapeError($raw);
        if ($shape !== null) {
            return $shape;
        }

        /** @var array<string, mixed> $raw */
        return $this->buildValidatedSchedulePayload($raw);
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|ToolResult
     */
    public function validateCreatePromptTemplate(array $arguments): array|ToolResult
    {
        $raw = $arguments['template_payload'] ?? null;
        $shape = $this->templateShapeError($raw);
        if ($shape !== null) {
            return $shape;
        }

        /** @var array<string, mixed> $raw */
        return $this->buildValidatedTemplatePayload($raw);
    }

    /**
     * @param  mixed $raw
     * @return ToolResult|null
     */
    private function scheduleShapeError(mixed $raw): ?ToolResult
    {
        if (!is_array($raw) || $raw === []) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `schedule_payload` object is required. '
                . 'Send a slim payload (template_id or raw_prompt, cron_expression or run_at, timezone) at the top level.',
            );
        }

        foreach (array_keys($raw) as $key) {
            if (!in_array($key, self::SCHEDULE_KNOWN_KEYS, true)) {
                return ToolResult::fail(
                    self::OP_CREATE_SCHEDULE . ': `' . $key . '` is not a known slim-payload key. '
                    . 'Allowed keys: ' . implode(', ', self::SCHEDULE_KNOWN_KEYS) . '.',
                );
            }
        }

        return null;
    }

    /**
     * @param  mixed $raw
     * @return ToolResult|null
     */
    private function templateShapeError(mixed $raw): ?ToolResult
    {
        if (!is_array($raw) || $raw === []) {
            return ToolResult::fail(
                self::OP_CREATE_TEMPLATE . ': `template_payload` object is required. '
                . 'Send name, prompt_template, and optional description/variables/max_steps at the top level.',
            );
        }

        foreach (array_keys($raw) as $key) {
            if (!in_array($key, self::TEMPLATE_KNOWN_KEYS, true)) {
                return ToolResult::fail(
                    self::OP_CREATE_TEMPLATE . ': `' . $key . '` is not a known slim-payload key. '
                    . 'Allowed keys: ' . implode(', ', self::TEMPLATE_KNOWN_KEYS) . '.',
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>|ToolResult
     */
    private function buildValidatedSchedulePayload(array $raw): array|ToolResult
    {
        $error = $this->guardCreateSchedulePrompt($raw);
        if ($error !== null) {
            return $error;
        }

        $error = $this->guardCreateScheduleCadence($raw);
        return $error ?? $this->assembleCreateSchedulePayload($raw);
    }

    /**
     * Either `template_id` (int) or `raw_prompt` (non-empty string) must be
     * supplied. Mutually exclusive with itself: both empty (fail), one of
     * each — type-check on the next pass.
     *
     * @param array<string, mixed> $raw
     */
    private function guardCreateSchedulePrompt(array $raw): ?ToolResult
    {
        $hasTemplateId = isset($raw['template_id']);
        $hasRawPrompt  = isset($raw['raw_prompt']);

        if (!$hasTemplateId && !$hasRawPrompt) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': either `template_id` (int) or `raw_prompt` (string) is required.',
            );
        }

        if ($hasTemplateId && !is_int($raw['template_id'])) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `template_id` must be a positive integer.',
            );
        }

        if ($hasRawPrompt && (!is_string($raw['raw_prompt']) || trim($raw['raw_prompt']) === '')) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `raw_prompt` must be a non-empty string.',
            );
        }

        return null;
    }

    /**
     * Walk the cadence + detail gates in order (cron/run_at mutual exclusion,
     * timezone parsing, cron/run_at well-formedness, max_steps_override) and
     * return the first failing ToolResult, or null on success.
     *
     * @param array<string, mixed> $raw
     */
    private function guardCreateScheduleCadence(array $raw): ?ToolResult
    {
        $whenResult = $this->firstFailure(
            $raw,
            [
                fn($r) => $this->guardCreateScheduleWhen($r),
            ],
        );
        if ($whenResult !== null) {
            return $whenResult;
        }

        $timezone = $this->resolveTimezone($raw);
        if ($timezone instanceof ToolResult) {
            return $timezone;
        }

        return $this->validateCreateScheduleDetails($raw, $timezone);
    }

    /**
     * Either `cron_expression` (recurring) or `run_at` (one-shot, ISO 8601)
     * is required — exactly one of them.
     *
     * @param array<string, mixed> $raw
     */
    private function guardCreateScheduleWhen(array $raw): ?ToolResult
    {
        $hasCron  = !empty($raw['cron_expression']);
        $hasRunAt = !empty($raw['run_at']);

        if ($hasCron && $hasRunAt) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `cron_expression` and `run_at` are mutually exclusive. '
                . 'Send exactly one — `cron_expression` for recurring, `run_at` (ISO 8601) for one-shot.',
            );
        }

        if (!$hasCron && !$hasRunAt) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': either `cron_expression` (recurring) or `run_at` (one-shot, ISO 8601) is required.',
            );
        }

        return null;
    }

    /**
     * Type-check `cron_expression`, `run_at`, and `max_steps_override`
     * after the timezone + recurrency gates have passed.
     *
     * @param array<string, mixed> $raw
     */
    private function validateCreateScheduleDetails(array $raw, string $timezone): ?ToolResult
    {
        if (!empty($raw['cron_expression'])) {
            $cronError = $this->validateCronExpression($raw['cron_expression']);
            if ($cronError !== null) {
                return $cronError;
            }
        }

        if (!empty($raw['run_at'])) {
            $runAtError = $this->validateRunAt($raw['run_at'], $timezone);
            if ($runAtError !== null) {
                return $runAtError;
            }
        }

        return $this->validateMaxStepsOverride($raw);
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function assembleCreateSchedulePayload(array $raw): array
    {
        return [
            'template_id'        => isset($raw['template_id']) ? (int) $raw['template_id'] : null,
            'raw_prompt'         => isset($raw['raw_prompt']) ? trim((string) $raw['raw_prompt']) : null,
            'cron_expression'    => !empty($raw['cron_expression']) ? trim((string) $raw['cron_expression']) : null,
            'run_at'             => !empty($raw['run_at']) ? (string) $raw['run_at'] : null,
            'timezone'           => $this->resolveTimezone($raw),
            'max_steps_override' => isset($raw['max_steps_override']) ? (int) $raw['max_steps_override'] : null,
            'is_active'          => (bool) ($raw['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>|ToolResult
     */
    private function buildValidatedTemplatePayload(array $raw): array|ToolResult
    {
        $error = $this->guardCreatePromptTemplateShape($raw);
        return $error ?? $this->assembleCreatePromptTemplatePayload($raw);
    }

    /**
     * Walks the template-payload gates (name, prompt_template, variables,
     * max_steps) in order. Returns the first failing ToolResult, or null
     * on success.
     *
     * @param  array<string, mixed> $raw
     */
    private function guardCreatePromptTemplateShape(array $raw): ?ToolResult
    {
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        if ($name === '' || mb_strlen($name) > self::TEMPLATE_NAME_MAX) {
            return ToolResult::fail(
                self::OP_CREATE_TEMPLATE . ': `name` is required (1..' . self::TEMPLATE_NAME_MAX . ' chars). '
                . 'Send `"name": "Daily summary"` at the top level.',
            );
        }

        $promptTemplate = is_string($raw['prompt_template'] ?? null) ? trim($raw['prompt_template']) : '';
        if ($promptTemplate === '') {
            return ToolResult::fail(
                self::OP_CREATE_TEMPLATE . ': `prompt_template` is required. '
                . 'Send the prompt body as a non-empty string.',
            );
        }

        return $this->firstFailure(
            $raw,
            [
                fn($r) => $this->validateVariables($r['variables'] ?? null),
                fn($r) => $this->validateMaxSteps($r),
            ],
        );
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function assembleCreatePromptTemplatePayload(array $raw): array
    {
        return [
            'name'            => trim((string) $raw['name']),
            'description'     => isset($raw['description']) && is_string($raw['description'])
                ? trim($raw['description'])
                : null,
            'prompt_template' => trim((string) $raw['prompt_template']),
            'variables'       => isset($raw['variables']) && is_array($raw['variables'])
                ? $raw['variables']
                : [],
            'max_steps'       => isset($raw['max_steps']) ? (int) $raw['max_steps'] : null,
            'is_active'       => (bool) ($raw['is_active'] ?? true),
        ];
    }

    /**
     * Runs a sequence of check callables against `$raw` and returns the
     * first non-null error. Stand-in for a chain of `if (... return)` to
     * keep method return-count low.
     *
     * @template T
     * @param  T                          $raw
     * @param  array<int, callable(T):?ToolResult> $checks
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
     * @param  mixed $value
     * @return ToolResult|null
     */
    private function validateVariables(mixed $value): ?ToolResult
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return ToolResult::fail(
                self::OP_CREATE_TEMPLATE . ': `variables` must be an array of `{key, default_value?}` entries.',
            );
        }

        foreach ($value as $i => $entry) {
            if (!$this->isWellFormedVariableEntry($entry)) {
                return ToolResult::fail(
                    self::OP_CREATE_TEMPLATE . ": variables[{$i}] must be `{key, default_value?}`. Send the variable key as a non-empty string.",
                );
            }
        }

        return null;
    }

    /**
     * @param mixed $entry
     */
    private function isWellFormedVariableEntry(mixed $entry): bool
    {
        if (!is_array($entry) || !isset($entry['key']) || !is_string($entry['key'])) {
            return false;
        }

        return $entry['key'] !== '';
    }

    /**
     * @param  array<string, mixed> $raw
     * @return ToolResult|null
     */
    private function validateMaxSteps(array $raw): ?ToolResult
    {
        $value = $raw['max_steps'] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_int($value) || $value < 1 || $value > 100) {
            return ToolResult::fail(
                self::OP_CREATE_TEMPLATE . ': `max_steps` must be an integer in 1..100.',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $raw
     * @return ToolResult|null
     */
    private function validateMaxStepsOverride(array $raw): ?ToolResult
    {
        $value = $raw['max_steps_override'] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_int($value) || $value < 1 || $value > 100) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `max_steps_override` must be an integer in 1..100.',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $raw
     * @return string|ToolResult
     */
    private function resolveTimezone(array $raw): string|ToolResult
    {
        $value = $raw['timezone'] ?? 'UTC';

        $error = $this->firstFailure(
            $value,
            [
                fn($v) => is_string($v)
                    ? null
                    : ToolResult::fail(
                        self::OP_CREATE_SCHEDULE . ': `timezone` must be a string (IANA identifier, e.g. "UTC", "Europe/Berlin").',
                    ),
                fn($v) => strlen((string) $v) <= self::TIMEZONE_MAX
                    ? null
                    : ToolResult::fail(
                        self::OP_CREATE_SCHEDULE . ': `timezone` must not exceed ' . self::TIMEZONE_MAX . ' characters.',
                    ),
                fn($v) => in_array($v, timezone_identifiers_list(), true)
                    ? null
                    : ToolResult::fail(
                        self::OP_CREATE_SCHEDULE . ': `timezone` must be a valid IANA identifier (e.g. "UTC", "Europe/Berlin").',
                    ),
            ],
        );

        return $error ?? (string) $value;
    }

    private function validateCronExpression(mixed $cron): ?ToolResult
    {
        if (!is_string($cron) || trim($cron) === '') {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `cron_expression` must be a non-empty string.',
            );
        }
        try {
            new CronExpression($cron);
        } catch (Throwable) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `cron_expression` is invalid. Use 5-field cron (e.g. "0 7 * * *" for 07:00 daily).',
            );
        }
        return null;
    }

    private function validateRunAt(mixed $runAt, string $timezone): ?ToolResult
    {
        if (!is_string($runAt) || trim($runAt) === '') {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `run_at` must be a non-empty ISO 8601 string.',
            );
        }
        try {
            new DateTimeImmutable($runAt, new DateTimeZone($timezone));
        } catch (Throwable) {
            return ToolResult::fail(
                self::OP_CREATE_SCHEDULE . ': `run_at` must be a valid ISO 8601 datetime parseable in `timezone` "' . $timezone . '".',
            );
        }
        return null;
    }
}
