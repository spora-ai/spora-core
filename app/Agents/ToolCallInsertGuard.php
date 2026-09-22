<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\Exceptions\ToolCallFieldOverflowException;

/**
 * Column-aware guard that prevents `tool_calls` from silently truncating
 * string fields at INSERT time. The original incident: `MediaTool`'s
 * `list_derivatives` description (~615 chars) exceeded the 500-char cap on
 * `operation_description` (migration 0019) and MariaDB returned 1406
 * ("Data too long for column") — a cryptic error surfacing as
 * "System Error: SQLSTATE[22001]…".
 *
 * This guard reads the live column lengths from the schema once per process
 * (MySQL/MariaDB preserve `varchar(N)` in `getColumns()`; SQLite does not
 * and silently drops it, so for SQLite we fall back to {@see STATIC_MAX_LENGTHS}),
 * then throws {@see ToolCallFieldOverflowException} on any string value that
 * would be truncated by the database. VARCHAR(N) is checked; TEXT-family
 * types are treated as unbounded.
 *
 * Called from {@see \Spora\Models\ToolCall::save()} before delegating to
 * Eloquent's parent save — overriding `save()` is the only path that always
 * runs under Spora's standalone Capsule setup (its EventDispatcher is
 * never wired into `Model::$dispatcher`, so `static::saving` listeners
 * silently never fire; same constraint that drove the override in
 * {@see \Spora\Models\LLMDriverConfiguration::save()}).
 */
final class ToolCallInsertGuard
{
    /**
     * Static fallback for engines that drop VARCHAR lengths from the
     * introspection result (SQLite reports `type='varchar'` without the
     * (N) suffix). These caps mirror the column widths declared in
     * migrations 000006 and 0019; if a future migration changes one,
     * update the value here in the same PR so dev (SQLite) and prod
     * (MySQL/MariaDB) stay in sync.
     *
     * @var array<string, int>
     */
    private const STATIC_MAX_LENGTHS = [
        'provider_call_id' => 100,
        'tool_name'        => 100,
        'tool_class'       => 200,
        'tool_type'        => 10,
        'status'           => 20,
        'operation'        => 100,
        'approval_note'    => 500,
    ];

    /** @var array<string, int|null>|null */
    private static ?array $columnMaxLengths = null;

    /**
     * @param  array<string, mixed> $fields  raw attribute map destined for `tool_calls`
     */
    public static function assertInsertable(array $fields, string $toolClass): void
    {
        foreach (self::columnMaxLengths() as $column => $maxLength) {
            if ($maxLength === null) {
                continue;
            }
            $value = $fields[$column] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $length = mb_strlen($value);
            if ($length > $maxLength) {
                throw new ToolCallFieldOverflowException(
                    field: $column,
                    actualLength: $length,
                    maxLength: $maxLength,
                    toolClass: $toolClass,
                );
            }
        }
    }

    /**
     * Drop the cached column map. Tests that mutate the schema between
     * assertions (e.g. the migration test) call this to force a re-read.
     */
    public static function resetCache(): void
    {
        self::$columnMaxLengths = null;
    }

    /**
     * @return array<string, int|null>  column → max character length, or null for unbounded
     */
    private static function columnMaxLengths(): array
    {
        if (self::$columnMaxLengths !== null) {
            return self::$columnMaxLengths;
        }

        $map = [];
        $schema = Capsule::schema();
        if ($schema->hasTable('tool_calls')) {
            foreach ($schema->getColumns('tool_calls') as $column) {
                $name = (string) $column['name'];
                $map[$name] = self::extractMaxLength((string) $column['type']);
            }
        }

        // SQLite (and any future engine that drops VARCHAR widths from
        // its introspection) sees null max lengths for bounded columns.
        // Backfill from STATIC_MAX_LENGTHS so dev/CI catches the same
        // regressions prod does.
        foreach (self::STATIC_MAX_LENGTHS as $column => $fallback) {
            if (($map[$column] ?? null) === null) {
                $map[$column] = $fallback;
            }
        }

        return self::$columnMaxLengths = $map;
    }

    /**
     * Extract the max-length suffix from a MySQL/MariaDB column-type
     * definition. `varchar(500)` → 500, `char(10)` → 10,
     * `text` / `bigint unsigned` / `int(11)` → null.
     *
     * VARCHAR is character-counted on utf8mb4; mb_strlen() in
     * {@see assertInsertable()} matches that semantics.
     */
    private static function extractMaxLength(string $typeDefinition): ?int
    {
        if (preg_match('/^(?:var)?char\s*\(\s*(\d+)\s*\)/i', $typeDefinition, $matches) === 1) {
            return (int) $matches[1];
        }
        return null;
    }
}
