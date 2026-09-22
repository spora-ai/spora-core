<?php

declare(strict_types=1);

namespace Spora\Services;

use InvalidArgumentException;

/**
 * Reusable max-length validator for any keyed payload (typically a row's
 * attribute map) destined for a table with bounded string columns.
 *
 * Centralises the loop that turns "value is N chars, column is M" into a
 * clear {@see InvalidArgumentException} before the database silently
 * truncates (MySQL/MariaDB returns 1406 "Data too long for column" at
 * INSERT/UPDATE time). Callers declare which columns are bounded and
 * how long they may be; the helper walks the payload and throws on the
 * first violation.
 *
 * Used by {@see \Spora\Models\ToolCall::save()} to defend `tool_calls`;
 * the same shape (a `STRING_COLUMN_MAX_LENGTHS` const + an override of
 * `save()` that calls {@see assertFits()}) applies to any other model
 * with bounded columns.
 */
final class MaxLengthValidator
{
    /**
     * @param  array<string, mixed> $values       keyed payload (e.g. `$model->getAttributes()`)
     * @param  array<string, int>   $maxLengths   bounded column name → max character length
     * @param  string               $rowLabel     human-readable table/row name for the error message (e.g. `"tool_calls"`)
     * @param  string               $originLabel  human-readable origin for the error message (e.g. the originating tool class)
     *
     * @throws InvalidArgumentException when any value's `mb_strlen` exceeds its declared cap.
     */
    public static function assertFits(array $values, array $maxLengths, string $rowLabel, string $originLabel): void
    {
        foreach ($maxLengths as $column => $max) {
            $value = $values[$column] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $length = mb_strlen($value);
            if ($length > $max) {
                throw new InvalidArgumentException(
                    sprintf(
                        '%s.%s for %s is %d chars; column limit is %d.',
                        $rowLabel,
                        $column,
                        $originLabel,
                        $length,
                        $max,
                    ),
                );
            }
        }
    }
}
