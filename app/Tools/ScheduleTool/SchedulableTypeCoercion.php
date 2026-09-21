<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

/**
 * Lenient type coercion for LLM-supplied patch fields.
 *
 * Background: the patch comes back from the LLM as JSON parsed via
 * `json_decode($raw, true)`. Some LLM drivers (and certain JSON-mode
 * configs) emit numeric values as strings — `{"max_steps_override": "25"}`
 * rather than `{"max_steps_override": 25}`. Booleans sometimes come
 * through as integer 0/1, or as the strings "true"/"false". The old
 * `is_int` / `is_bool` checks rejected all of those, surfacing as
 * "must be a boolean" / "must be an integer in 1..100" errors at the
 * tool boundary — particularly with the `update_schedule` /
 * `update_prompt_template` operations.
 *
 * These helpers coerce and return null when the value is not
 * representable as the target scalar. The validator then decides
 * whether to error and the assembled payload uses the canonical PHP
 * form.
 */
trait SchedulableTypeCoercion
{
    /**
     * Normalise an LLM boolean emission to a PHP bool.
     *
     * Accepts:
     *   - PHP bool               (true / false)
     *   - PHP int   0 / 1
     *   - String    "true" | "false" | "1" | "0" | "" (case-insensitive, trimmed)
     *
     * Returns null when the value cannot be interpreted as a boolean.
     * `null` (PHP) and anything else returns null.
     */
    private function coerceBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0' || $lower === '') {
                return false;
            }
        }

        return null;
    }

    /**
     * Normalise an LLM positive integer emission to a PHP int.
     *
     * Accepts:
     *   - PHP int >= 1
     *   - PHP float that's an integral value >= 1   (e.g. 25.0)
     *   - Numeric string containing only digits       (e.g. "25")
     *
     * Returns null for 0, negative, non-finite floats, non-numeric
     * strings, booleans, arrays, objects, or PHP null.
     */
    private function coercePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }
        if (is_float($value)) {
            if (!is_finite($value) || $value !== floor($value) || $value < 1) {
                return null;
            }
            return (int) $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !ctype_digit($trimmed)) {
                return null;
            }
            $int = (int) $trimmed;

            return $int >= 1 ? $int : null;
        }

        return null;
    }

    /**
     * Normalise an LLM positive-or-zero integer (for `id` fields that
     * must come back as a non-negative int but allow 0 as a placeholder).
     */
    private function coerceNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value) && is_finite($value) && $value === floor($value) && $value >= 0) {
            return (int) $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !ctype_digit($trimmed)) {
                return null;
            }
            return (int) $trimmed;
        }

        return null;
    }

    /**
     * Render a value into a short human-readable phrase for error
     * messages — e.g. `bool(true)`, `string("42")`, `int(25)`, `null`.
     *
     * Strings are wrapped in double quotes so `describeValue('null')`
     * is distinguishable from `describeValue(null)`. The bare four-
     * character word "null" without quotes was the smoking gun in a
     * bug report where an upstream serializer was producing the
     * literal string "null" instead of JSON null — the unquoted
     * output made the agent and operators believe the validator was
     * receiving JSON null and rejecting it.
     *
     * Scalar only; arrays/objects fall back to their gettype() label.
     */
    private function describeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'bool(' . ($value ? 'true' : 'false') . ')';
        }
        if (is_int($value) || is_float($value)) {
            return gettype($value) . '(' . $value . ')';
        }
        if (is_string($value)) {
            return 'string("' . $this->truncateString($value, 32) . '")';
        }

        return gettype($value);
    }

    private function truncateString(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - 1) . '…';
    }
}
