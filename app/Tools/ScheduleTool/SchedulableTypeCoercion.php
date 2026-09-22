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

        return match (true) {
            $value === 1                => true,
            $value === 0                => false,
            is_string($value)           => $this->coerceBoolFromString($value),
            default                     => null,
        };
    }

    /**
     * Helper for {@see coerceBool()} — handles the string branch in
     * isolation so the parent keeps its return count manageable.
     */
    private function coerceBoolFromString(string $value): ?bool
    {
        $lower = strtolower(trim($value));

        return match ($lower) {
            'true', '1'  => true,
            'false', '0', '' => false,
            default      => null,
        };
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
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        return match (true) {
            is_int($value)   => $this->intInRange($value, 1, PHP_INT_MAX),
            is_float($value) => $this->intFromIntegralFloat($value, 1),
            default          => $this->intFromDigitString($value, 1),
        };
    }

    /**
     * Normalise an LLM positive-or-zero integer (for `id` fields that
     * must come back as a non-negative int but allow 0 as a placeholder).
     */
    private function coerceNonNegativeInt(mixed $value): ?int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        return match (true) {
            is_int($value)   => $this->intInRange($value, 0, PHP_INT_MAX),
            is_float($value) => $this->intFromIntegralFloat($value, 0),
            default          => $this->intFromDigitString($value, 0),
        };
    }

    private function intInRange(int $value, int $min, int $max): ?int
    {
        return ($value >= $min && $value <= $max) ? $value : null;
    }

    private function intFromIntegralFloat(float $value, int $min): ?int
    {
        if (!is_finite($value) || $value !== floor($value) || $value < $min) {
            return null;
        }

        return (int) $value;
    }

    private function intFromDigitString(string $value, int $min): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }

        $int = (int) $trimmed;

        return $int >= $min ? $int : null;
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
        if (is_string($value)) {
            return 'string("' . $this->truncateString($value, 32) . '")';
        }

        return match (true) {
            $value === null                          => 'null',
            is_bool($value)                          => 'bool(' . ($value ? 'true' : 'false') . ')',
            is_int($value) || is_float($value)       => gettype($value) . '(' . $value . ')',
            default                                  => gettype($value),
        };
    }

    private function truncateString(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - 1) . '…';
    }
}
