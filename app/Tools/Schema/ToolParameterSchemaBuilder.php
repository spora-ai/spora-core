<?php

declare(strict_types=1);

namespace Spora\Tools\Schema;

use ReflectionAttribute;
use ReflectionClass;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Exceptions\ToolParameterSchemaException;
use stdClass;

/**
 * Builds the JSON Schema "parameters" object from a tool's `#[ToolParameter]`
 * and `#[ToolOperation]` attributes via reflection.
 *
 * Used by HasParameterSchema (and AbstractTool, which composes the trait) to
 * satisfy ToolInterface::getParametersSchema() without each tool hand-rolling
 * the schema literal.
 *
 * The synthesized property — generated when the tool has `#[ToolOperation]`
 * declarations — uses the first operation's `discriminatorKey` as the property
 * name (default `action`) and lists every declared operation in the `enum`.
 * Tool authors must not also declare a `#[ToolParameter]` for the
 * discriminator; the builder owns that property.
 *
 * The returned schema carries one internal key, `__required_when` (a map from
 * property name to a list of op names), used by OperationSchemaFilter to
 * narrow `required[]` per agent. The filter strips the key before the schema
 * reaches the LLM — providers never see it.
 */
final class ToolParameterSchemaBuilder
{
    /** @internal Filter-only side channel; see OperationSchemaFilter. */
    public const REQUIRED_WHEN_KEY = '__required_when';

    /**
     * Build the JSON Schema "parameters" object from a tool's attributes.
     *
     * @param  object|class-string $target Tool instance or fully-qualified class name.
     * @return array{
     *   type: "object",
     *   properties: array<string, array<string, mixed>>|stdClass,
     *   required: list<string>,
     *   __required_when: array<string, list<string>>,
     * }
     */
    public static function build(object|string $target): array
    {
        $ref              = new ReflectionClass($target);
        $properties       = [];
        $required         = [];
        $requiredWhen     = [];
        $discriminatorKey = null;

        $operationAttrs = self::collectInheritedAttributes($ref, ToolOperation::class);
        if (count($operationAttrs) >= 2) {
            /** @var list<ToolOperation> $operations */
            $operations = array_map(static fn($attr) => $attr->newInstance(), $operationAttrs);

            $discriminatorKey = $operations[0]->discriminatorKey;
            $opNames          = array_map(static fn(ToolOperation $op) => $op->name, $operations);

            $properties[$discriminatorKey] = [
                'type'        => 'string',
                'description' => self::buildDiscriminatorDescription($operations),
                'enum'        => $opNames,
            ];
            $required[] = $discriminatorKey;
        }

        foreach (self::collectInheritedAttributes($ref, ToolParameter::class) as $attr) {
            /** @var ToolParameter $param */
            $param = $attr->newInstance();

            if ($discriminatorKey !== null && $param->name === $discriminatorKey) {
                throw new ToolParameterSchemaException(sprintf(
                    'Tool %s declares #[ToolParameter(name: %s)] which collides with the synthesized '
                    . 'operation discriminator. Remove the parameter (the builder owns this property) '
                    . 'or pick a different discriminatorKey on its #[ToolOperation] attributes.',
                    $ref->getName(),
                    var_export($param->name, true),
                ));
            }

            $properties[$param->name] = self::propertyJson($param);

            if (is_array($param->required)) {
                $requiredWhen[$param->name] = $param->required;
                $required[]                  = $param->name;
            } elseif ($param->required && $param->default === null) {
                $required[] = $param->name;
            }
        }

        return [
            'type'             => 'object',
            'properties'       => $properties === [] ? new stdClass() : $properties,
            'required'         => array_values(array_unique($required)),
            self::REQUIRED_WHEN_KEY => $requiredWhen,
        ];
    }

    /**
     * @template T of object
     * @param  class-string<T>      $attributeClass
     * @return list<ReflectionAttribute<T>>
     */
    private static function collectInheritedAttributes(ReflectionClass $ref, string $attributeClass): array
    {
        $attrs    = [];
        $current  = $ref;
        while ($current !== false) {
            foreach ($current->getAttributes($attributeClass) as $attr) {
                $attrs[] = $attr;
            }
            $current = $current->getParentClass();
        }
        return $attrs;
    }

    /**
     * @return array<string, mixed>
     */
    private static function propertyJson(ToolParameter $param): array
    {
        $json = [
            'type'        => $param->type,
            'description' => $param->description,
        ];

        if ($param->enum !== []) {
            $json['enum'] = $param->enum;
        }
        if ($param->minimum !== null) {
            $json['minimum'] = $param->minimum;
        }
        if ($param->maximum !== null) {
            $json['maximum'] = $param->maximum;
        }
        if ($param->format !== null) {
            $json['format'] = $param->format;
        }
        if ($param->items !== null) {
            $json['items'] = $param->items;
        }
        if ($param->default !== null) {
            $json['default'] = $param->default;
        }

        return $json;
    }

    /**
     * @param list<ToolOperation> $operations
     */
    private static function buildDiscriminatorDescription(array $operations): string
    {
        $names = array_map(static fn(ToolOperation $op) => $op->name, $operations);
        return 'The operation to perform: ' . implode(', ', $names);
    }
}
