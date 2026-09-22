<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\Exceptions\ToolCallFieldOverflowException;

/**
 * Defends `tool_calls` against silent VARCHAR truncation on save. Called
 * from {@see \Spora\Models\ToolCall::save()} rather than a `static::saving`
 * listener because Spora's standalone Capsule never wires an
 * EventDispatcher into `Model::$dispatcher` — same constraint that drove
 * {@see \Spora\Models\LLMDriverConfiguration::save()}.
 */
final class ToolCallInsertGuard
{
    /**
     * Fallback when live introspection drops VARCHAR widths (SQLite).
     * Mirror the column widths from migrations 000006 + 0019; if a future
     * migration widens or narrows one, update both in the same PR.
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

    /** @param array<string, mixed> $fields */
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

    /** @return array<string, int|null> */
    private static function columnMaxLengths(): array
    {
        if (self::$columnMaxLengths !== null) {
            return self::$columnMaxLengths;
        }

        $map = [];
        $schema = Capsule::schema();
        if ($schema->hasTable('tool_calls')) {
            foreach ($schema->getColumns('tool_calls') as $column) {
                $map[(string) $column['name']] = self::extractMaxLength((string) $column['type']);
            }
        }

        foreach (self::STATIC_MAX_LENGTHS as $column => $fallback) {
            if (($map[$column] ?? null) === null) {
                $map[$column] = $fallback;
            }
        }

        return self::$columnMaxLengths = $map;
    }

    /**
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
