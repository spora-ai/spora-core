<?php

declare(strict_types=1);

namespace Spora\Tools\Schema;

use ReflectionAttribute;
use ReflectionClass;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Exceptions\ToolParameterSchemaException;
use Spora\Tools\ToolSettingSchema;
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
 *
 * LLM-side enrichment via `enumSource`: when a `#[ToolParameter]` declares
 * `enumSource: 'setting_key'`, the builder pulls the named setting's
 * resolved agent ids from `$enumSourceValues` and the matching human-
 * readable labels from `$enumSourceLabels`, then (a) populates `enum` on
 * the property (only when the parameter has no static `enum`) and (b)
 * appends a `" Allowed values: …"` suffix to the description. An empty
 * source is silently skipped (no enum, no suffix) — emitting an empty
 * `enum` would be invalid JSON Schema, and an empty suffix would be
 * misleading to the model. Static `enum` always wins; `enumSource` is
 * only consulted when the parameter declares no static enum.
 */
final class ToolParameterSchemaBuilder
{
    /** @internal Filter-only side channel; see OperationSchemaFilter. */
    public const REQUIRED_WHEN_KEY = '__required_when';

    /**
     * Build the JSON Schema "parameters" object from a tool's attributes.
     *
     * @param  object|class-string $target           Tool instance or fully-qualified class name.
     * @param  array<string, list<int|string>>       $enumSourceValues  setting key => resolved ids to populate `enum` from.
     *                                                             Only consulted for parameters with `enumSource: '…'`
     *                                                             and no static `enum`. Empty list = no injection.
     * @param  array<string, list<string>>           $enumSourceLabels  setting key => resolved human-readable
     *                                                             labels (e.g. `"Legal Agent (#11)"`) appended to
     *                                                             the parameter description as
     *                                                             `" Allowed values: …"`. Empty list = no suffix.
     * @return array{
     *   type: "object",
     *   properties: array<string, array<string, mixed>>|stdClass,
     *   required: list<string>,
     *   __required_when: array<string, list<string>>,
     * }
     */
    public static function build(
        object|string $target,
        array $enumSourceValues = [],
        array $enumSourceLabels = [],
    ): array {
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

        // Validate `enumSource` references before emitting any property so a
        // misconfigured tool fails fast on the first offender with a named
        // exception — instead of silently emitting partial schemas where some
        // params are augmented and others fall through to "static enum only".
        self::validateEnumSources($ref);

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

            $properties[$param->name] = self::propertyJson(
                $param,
                $param->enumSource !== null && isset($enumSourceValues[$param->enumSource])
                    ? $enumSourceValues[$param->enumSource]
                    : [],
                $param->enumSource !== null && isset($enumSourceLabels[$param->enumSource])
                    ? $enumSourceLabels[$param->enumSource]
                    : [],
            );

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
     * Validate every `enumSource: '…'` declared on a parameter of this tool
     * against the `#[ToolSetting]` schema on the same class.
     *
     * Throws ToolParameterSchemaException on the first offender if the
     * named setting does not exist, is not `exposeToLlm: true`, is not
     * `type: 'multi-select'`, or is not `resolveAs: 'agent'`. The shape
     * constraints match what the LLM-facing schema actually consumes:
     * the enum needs ints (from agent multi-selects) and the description
     * suffix needs the resolved `"Name (#id)"` strings. Allowing
     * `resolveAs: 'skill'` / `'raw'` here would silently produce an empty
     * `enum` because the runtime source map only carries agent-typed
     * settings.
     */
    private static function validateEnumSources(ReflectionClass $ref): void
    {
        $settingsByKey = [];
        foreach (ToolSettingSchema::collect($ref->getName()) as $setting) {
            $settingsByKey[$setting->key] = $setting;
        }

        foreach (self::collectInheritedAttributes($ref, ToolParameter::class) as $attr) {
            /** @var ToolParameter $param */
            $param = $attr->newInstance();
            if ($param->enumSource === null) {
                continue;
            }

            $setting = $settingsByKey[$param->enumSource] ?? null;
            if ($setting === null) {
                throw new ToolParameterSchemaException(sprintf(
                    'Tool %s declares #[ToolParameter(name: %s, enumSource: %s)] '
                    . 'but no #[ToolSetting(key: %s)] exists on the class. '
                    . 'Either add the setting or remove enumSource.',
                    $ref->getName(),
                    var_export($param->name, true),
                    var_export($param->enumSource, true),
                    var_export($param->enumSource, true),
                ));
            }
            if (!$setting->exposeToLlm) {
                throw new ToolParameterSchemaException(sprintf(
                    'Tool %s declares #[ToolParameter(name: %s, enumSource: %s)] '
                    . 'but the named #[ToolSetting] is not exposeToLlm: true. '
                    . 'The LLM-facing schema has no source values to inject.',
                    $ref->getName(),
                    var_export($param->name, true),
                    var_export($param->enumSource, true),
                ));
            }
            if ($setting->type !== 'multi-select') {
                throw new ToolParameterSchemaException(sprintf(
                    'Tool %s declares #[ToolParameter(name: %s, enumSource: %s)] '
                    . 'but the named #[ToolSetting] has type %s. enumSource only '
                    . 'supports multi-select settings.',
                    $ref->getName(),
                    var_export($param->name, true),
                    var_export($param->enumSource, true),
                    var_export($setting->type, true),
                ));
            }
            if ($setting->resolveAs !== 'agent') {
                throw new ToolParameterSchemaException(sprintf(
                    'Tool %s declares #[ToolParameter(name: %s, enumSource: %s)] '
                    . 'but the named #[ToolSetting] has resolveAs %s. enumSource '
                    . 'only supports resolveAs: agent.',
                    $ref->getName(),
                    var_export($param->name, true),
                    var_export($param->enumSource, true),
                    var_export($setting->resolveAs, true),
                ));
            }
        }
    }

    /**
     * @param  list<int|string> $values  Resolved ids for the enum (e.g. `[11, 4]`). Ignored when the parameter has a static `enum`.
     * @param  list<string>     $labels  Resolved human-readable labels for the description suffix (e.g. `['Legal Agent (#11)', 'Sales Agent (#4)']`).
     * @return array<string, mixed>
     */
    private static function propertyJson(
        ToolParameter $param,
        array $values,
        array $labels,
    ): array {
        $json = [
            'type'        => $param->type,
            'description' => $param->description,
        ];

        if ($param->enum !== []) {
            // Static enum is the developer-tight constraint; it always wins
            // over enumSource. The runtime may want to inject enumSource but
            // if the developer pinned values here the schema must reflect
            // those — surprising the LLM with the runtime list while the
            // source code says otherwise would be a footgun.
            $json['enum'] = $param->enum;
        } elseif ($param->enumSource !== null && $values !== []) {
            $json['enum'] = $values;
        }

        if ($param->enumSource !== null && $labels !== []) {
            $json['description'] .= ' Allowed values: ' . implode(', ', $labels);
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
