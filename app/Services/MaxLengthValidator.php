<?php

declare(strict_types=1);

namespace Spora\Services;

use InvalidArgumentException;

/**
 * Throws {@see InvalidArgumentException} before MySQL/MariaDB silently
 * truncate a string column with SQLSTATE 22001. Defended models use
 * {@see \Spora\Models\Concerns\HasStringColumnLengthValidation}, which
 * overrides `save()` (Spora's standalone Capsule never wires an
 * EventDispatcher into `Model::$dispatcher` — same constraint as
 * {@see \Spora\Models\Principal::save()}).
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
