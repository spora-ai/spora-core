<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Tool-call argument normalisation helpers.
 *
 * Two layers:
 *
 *  1. `unboxItemWrappers()` is structural and schema-agnostic. Some
 *     LLM tool-call generators wrap a nested array as
 *     `{ "item": [ ... ] }` even when the JSON Schema declares
 *     `type: array`; the wrapper is unambiguous (one named key whose
 *     value is an array) and unwrapping it costs nothing for legitimate
 *     single-key objects (they only ever wrap when the value is array-shaped).
 *
 *  2. `coerceScalarStrings()` is type-aware and reads the JSON
 *     Schema's `properties` map to coerce `"true"`/`"false"` and
 *     numeric strings into the declared `boolean`/`integer`/`number`
 *     types. Only the keys declared in the schema are coerced, so
 *     legitimate string-valued fields are left intact.
 *
 * Both helpers walk the arguments tree recursively and return a new
 * tree — input is read-only. Nested objects (associative arrays) and
 * lists (sequential arrays) are both handled; the function preserves
 * the list/assoc distinction in its output.
 */
final class ToolArgumentsNormalizer
{
    /**
     * Recursively unwrap any `{ "item": [...] }` or `{ "items": [...] }`
     * shape into a plain JSON array. Leaves other single-key objects
     * alone — the unwrap key has to be exactly `item` or `items` AND
     * the value has to be an array.
     *
     * @param  array<mixed> $args
     * @return array<mixed>
     */
    public static function unboxItemWrappers(array $args): array
    {
        return self::walkUnbox($args);
    }

    /**
     * Walk `$args` and coerce string-valued scalars to the JSON Schema
     * `type` declared in `$propertiesSchema`. Only keys present in the
     * schema are coerced; everything else is left intact so that
     * legitimate string fields (and free-form `payload` objects with
     * their own schema at the tool level) don't get false-positive
     * coercion.
     *
     * @param  array<mixed>                              $args
     * @param  array<string, array<string, mixed>>       $propertiesSchema Output of `ToolParameterSchemaBuilder::build($tool)['properties']`.
     * @return array<mixed>
     */
    public static function coerceScalarStrings(array $args, array $propertiesSchema): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            $childSchema = $propertiesSchema[$k] ?? null;
            $out[$k] = self::walkCoerce($v, is_array($childSchema) ? $childSchema : null);
        }
        return $out;
    }

    /**
     * @param array<mixed>|mixed $value
     * @return array<mixed>|mixed
     */
    private static function walkUnbox(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isUnwrappableShape($value)) {
            $inner = $value[array_key_first($value)];
            return self::walkUnbox($inner);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::walkUnbox($v);
        }
        return $out;
    }

    /**
     * Coerce `$value` against the per-key schema (or no schema at all).
     * `$typeSchema` is the schema for a single property — its `type`
     * drives scalar coercion; its `properties` drives recursion.
     *
     * @param  array<mixed>|mixed             $value
     * @param  array<string, mixed>|null      $typeSchema
     * @return array<mixed>|mixed
     */
    private static function walkCoerce(mixed $value, ?array $typeSchema): mixed
    {
        if ($typeSchema !== null
            && isset($typeSchema['type'])
            && is_string($typeSchema['type'])
        ) {
            $value = self::coerceOne($value, $typeSchema['type']);
        }
        if (!is_array($value)) {
            return $value;
        }
        // Recurse only if the schema declares nested object properties.
        // Lists (sequential arrays) without a schema are walked with null
        // sub-schemas so the values pass through.
        $sub = isset($typeSchema['properties']) && is_array($typeSchema['properties'])
            ? $typeSchema['properties']
            : null;
        if ($sub === null) {
            if (array_is_list($value)) {
                $list = [];
                foreach ($value as $i => $item) {
                    $list[$i] = self::walkCoerce($item, null);
                }
                return $list;
            }
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $child = $sub[$k] ?? null;
            $out[$k] = self::walkCoerce($v, is_array($child) ? $child : null);
        }
        return $out;
    }

    /**
     * Apply the schema-declared type coercion to a single value.
     * `string → boolean / integer / number` only — never the reverse,
     * never `object → anything`. Strings that fail to parse for the
     * declared numeric type are returned unchanged so the tool's
     * downstream validator surfaces a clearer error.
     *
     * @param mixed $value
     */
    private static function coerceOne(mixed $value, string $type): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        return match ($type) {
            'boolean' => match (strtolower($value)) {
                'true'  => true,
                'false' => false,
                default => $value,
            },
            'integer' => self::coerceInt($value),
            'number'  => self::coerceFloat($value),
            default   => $value,
        };
    }

    private static function coerceInt(string $value): int|string
    {
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return $value;
    }

    private static function coerceFloat(string $value): float|string
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function isUnwrappableShape(mixed $value): bool
    {
        if (!is_array($value) || array_is_list($value)) {
            return false;
        }
        if (count($value) !== 1) {
            return false;
        }
        $key   = array_key_first($value);
        $inner = $value[$key];
        return ($key === 'item' || $key === 'items') && is_array($inner);
    }
}
