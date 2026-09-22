<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

use Spora\Tools\ValueObjects\ToolResult;

/**
 * Positive-integer id parsers for the ScheduleTool's per-row operations.
 *
 * Two parsers share the look-and-feel of `AgentTargetResolver`:
 *   - `parseScheduleId()`   for `read_schedule`, `update_schedule`,
 *                           `delete_schedule`, `trigger_schedule`.
 *   - `parseTemplateId()`   for `read_prompt_template`,
 *                           `update_prompt_template`,
 *                           `delete_prompt_template`.
 *
 * Both reject zero, non-numeric strings, and floats with the same
 * operation-prefixed error so the LLM can grep every fail site with
 * one prefix. Missing keys (`read_schedule` with no `schedule_id`,
 * `create_*` etc.) return `null` and the caller decides whether
 * "missing" is legal.
 */
final class ScheduleTargetResolver
{
    public const OP_SCHEDULE = 'schedule';
    public const OP_TEMPLATE = 'prompt_template';

    public const SCHEDULE_ID_POSITIVE_INTEGER_MSG = '`schedule_id` must be a positive integer.';
    public const TEMPLATE_ID_POSITIVE_INTEGER_MSG = '`template_id` must be a positive integer.';

    /**
     * @return int|ToolResult
     */
    public function parseScheduleId(mixed $raw): int|ToolResult
    {
        return $this->parsePositiveInt(
            $raw,
            self::OP_SCHEDULE,
            self::SCHEDULE_ID_POSITIVE_INTEGER_MSG,
        );
    }

    /**
     * @return int|ToolResult
     */
    public function parseTemplateId(mixed $raw): int|ToolResult
    {
        return $this->parsePositiveInt(
            $raw,
            self::OP_TEMPLATE,
            self::TEMPLATE_ID_POSITIVE_INTEGER_MSG,
        );
    }

    /**
     * @return int|ToolResult
     */
    private function parsePositiveInt(mixed $raw, string $op, string $msg): int|ToolResult
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_bool($raw) || !is_numeric($raw)) {
            return ToolResult::fail("{$op}: {$msg}");
        }
        $n = (int) $raw;
        return $n > 0 ? $n : ToolResult::fail("{$op}: {$msg}");
    }
}
