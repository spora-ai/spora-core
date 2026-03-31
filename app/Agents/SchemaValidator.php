<?php

declare(strict_types=1);

namespace Spora\Agents;

use InvalidArgumentException;

/**
 * Lightweight JSON Schema validator for tool argument arrays.
 *
 * Only validates what matters for tool safety:
 *   - All required fields are present.
 *   - Each supplied value's PHP type is compatible with the declared JSON Schema type.
 *
 * Extra keys beyond what the schema declares are silently permitted so that human
 * reviewers can add diagnostic fields without breaking execution.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $schema  JSON Schema "parameters" object from {@see InputToolInterface::getParametersSchema()}.
     *
     * @throws InvalidArgumentException  When a required field is missing or a type is incompatible.
     */
    public static function validate(array $arguments, array $schema): void
    {
        $required   = $schema['required']   ?? [];
        $properties = $schema['properties'] ?? [];

        foreach ($required as $field) {
            if (!array_key_exists($field, $arguments)) {
                throw new InvalidArgumentException(
                    "Required argument '{$field}' is missing from the approved arguments.",
                );
            }
        }

        foreach ($properties as $key => $propSchema) {
            if (!array_key_exists($key, $arguments)) {
                continue; // optional field not supplied — fine
            }

            $expectedType = $propSchema['type'] ?? null;

            if ($expectedType === null) {
                continue;
            }

            $value      = $arguments[$key];
            $compatible = match ($expectedType) {
                'string'           => is_string($value),
                'integer'          => is_int($value),
                'number'           => is_int($value) || is_float($value),
                'boolean'          => is_bool($value),
                'array'            => is_array($value),
                'object'           => is_array($value) || is_object($value),
                'null'             => is_null($value),
                default            => true,
            };

            if (!$compatible) {
                $actual = get_debug_type($value);
                throw new InvalidArgumentException(
                    "Argument '{$key}' expects JSON Schema type '{$expectedType}', got '{$actual}'.",
                );
            }
        }
    }
}
