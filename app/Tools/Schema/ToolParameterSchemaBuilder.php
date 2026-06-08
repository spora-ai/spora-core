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
 * Builds a JSON Schema `parameters` object for a tool from its #[ToolParameter]
 * and #[ToolOperation] attributes via reflection.
 *
 * Used by HasParameterSchema (and AbstractTool, which composes the trait) to
 * satisfy ToolInterface::getParametersSchema() without each tool hand-rolling
 * the schema literal.
 *
 * The synthesized property — generated when the tool has #[ToolOperation]
 * declarations — uses the first operation's `discriminatorKey` as the property
 * name (default 'action') and lists every declared operation in the `enum`.
 * Tool authors must not also declare a #[ToolParameter] for the discriminator;
 * the builder owns that property.
 */
final class ToolParameterSchemaBuilder
{
    /**
     * Build the JSON Schema "parameters" object from a tool's attributes.
     *
     * @param  object|class-string $target Tool instance or fully-qualified class name.
     * @return array{
     *   type: "object",
     *   properties: array<string, array<string, mixed>>|stdClass,
     *   required: list<string>
     * }
     */
    public static function build(object|string $target): array
    {
        $ref = new ReflectionClass($target);

        $properties        = [];
        $required          = [];
        $discriminatorKey  = null;

        // 1. Discriminator synthesis — runs first so the dispatch field is
        //    always the first property in the resulting schema, matching the
        //    convention every existing multi-op tool followed by hand.
        //
        //    Skipped for single-op tools: the LLM has no choice to make, and
        //    HasOperations::getOperationName() already falls back to the one
        //    declared op when the argument is absent. Matches the hand-rolled
        //    convention used by CalculatorTool, ReadUrlTool, etc.
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

        // 2. Author-declared parameters — preserves attribute declaration order.
        //    Walks up the class hierarchy so an abstract base class can declare
        //    shared parameters (e.g. AbstractMemoryTool's name/content/summary/order)
        //    that concrete subclasses inherit.
        foreach (self::collectInheritedAttributes($ref, ToolParameter::class) as $attr) {
            /** @var ToolParameter $param */
            $param = $attr->newInstance();

            // Fail fast on collision: silently overwriting the synthesized
            // discriminator would let the LLM submit values outside the
            // operation enum and surface as a baffling "Unknown operation"
            // at dispatch time. Force the author to rename one.
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

            // `required: true` + a non-null `default` is contradictory in JSON
            // Schema terms — drop the required marker so the LLM can omit the
            // parameter and the default applies. The `default` key still surfaces
            // in the property JSON for the LLM's reference.
            if ($param->required && $param->default === null) {
                $required[] = $param->name;
            }
        }

        return [
            'type'       => 'object',
            'properties' => $properties === [] ? new stdClass() : $properties,
            'required'   => $required,
        ];
    }

    /**
     * Walk the class hierarchy (concrete → root parent) and return all attribute
     * instances of the given type. Parent declarations come AFTER child ones,
     * mirroring how a hand-rolled subclass would extend its base — child overrides
     * are visible first; inherited defaults follow.
     *
     * @template T of object
     * @param  class-string<T>      $attributeClass
     * @return list<ReflectionAttribute<T>>
     */
    private static function collectInheritedAttributes(ReflectionClass $ref, string $attributeClass): array
    {
        $attrs = [];
        $current = $ref;
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
