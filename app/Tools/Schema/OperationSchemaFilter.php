<?php

declare(strict_types=1);

namespace Spora\Tools\Schema;

use stdClass;

/**
 * Narrows a tool's parameter schema to only advertise the operations the
 * current agent is allowed to invoke.
 *
 * The Orchestrator builds the LLM function-calling payload per agent. For
 * multi-operation tools, the discriminator property's `enum` is filtered to
 * the subset of operations enabled for the agent (via `#[ToolOperation]`
 * defaults plus AgentToolOperationOverride rows). Operations the agent cannot
 * invoke must not appear in the schema, otherwise the LLM may attempt them.
 *
 * Per-parameter `required[]` is also narrowed: a parameter declared with
 * `#[ToolParameter(required: ['format'])` (or any list of op names) only
 * stays required when at least one of those ops survives the action-enum
 * filter. Likewise the parameter itself is dropped from `properties` when
 * its binding does not intersect the allowed-op set — advertising
 * per-op-bound parameters the LLM cannot use pollutes the audit log with
 * defensive empty stubs (`agent: []`, `content: ""`, `payload: []`) on every
 * call. The builder stashes this per-op binding in a single schema-level
 * side-channel (`__required_when`, keyed by property name) that this filter
 * reads and strips before the schema is serialised to the LLM. The filter
 * passes the side-channel through unchanged when no per-op parameters are
 * present, so the cost of the strip is one key unset.
 *
 * Extracted from Orchestrator::filterSchemaForOperations so the logic is
 * testable in isolation. The Orchestrator passes the live discriminator key
 * — read from the tool's `#[ToolOperation]` declarations — so tools that use
 * a non-default key (e.g. WorldNewsApiTool uses 'operation') are filtered
 * correctly.
 *
 * `filterForOperation()` is the runtime counterpart used by `SchemaValidator`
 * to apply the same per-op narrowing against the single op currently being
 * executed. Both entry points read the same `__required_when` side channel.
 */
final class OperationSchemaFilter
{
    /**
     * @param array{
     *   type?: string,
     *   properties?: array<string, mixed>|stdClass,
     *   required?: list<string>,
     *   __required_when?: array<string, list<string>>,
     * } $schema
     * @param  list<string> $allowedOps       Operation names the agent may invoke.
     * @param  string       $discriminatorKey The schema property whose `enum` lists operation names.
     * @return array{type: "object", properties: stdClass|array<string, mixed>, required: list<string>}
     */
    public static function filter(array $schema, array $allowedOps, string $discriminatorKey = 'action'): array
    {
        $allowedOpsSet = array_flip($allowedOps);

        $properties = self::normaliseProperties($schema['properties'] ?? []);
        $properties = self::narrowDiscriminatorEnum($properties, $discriminatorKey, $allowedOpsSet);

        $requiredWhen = $schema[ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY] ?? [];
        if ($requiredWhen !== []) {
            $required   = array_values(array_filter(
                $schema['required'] ?? [],
                static fn(string $name) => self::bindingIntersects($requiredWhen[$name] ?? null, $allowedOpsSet),
            ));
            $properties = array_filter(
                $properties,
                static fn(string $name) => self::bindingIntersects($requiredWhen[$name] ?? null, $allowedOpsSet),
                ARRAY_FILTER_USE_KEY,
            );
        } else {
            $required = $schema['required'] ?? [];
        }

        $schema['type']       = $schema['type'] ?? 'object';
        $schema['properties'] = $properties === [] ? new stdClass() : $properties;
        $schema['required']   = $required;
        unset($schema[ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY]);

        return $schema;
    }

    /**
     * Narrows `required[]` for a single operation the runtime is about to execute.
     *
     * The LLM-facing schema (built via `filter()`) advertises the action's
     * required params to the model. The runtime `SchemaValidator` needs the
     * same per-op narrowing so a `time(action: "now")` call doesn't fail with
     * "Required argument 'epoch' is missing" — `epoch` is bound to the `format`
     * op only, not `now`.
     *
     * Unlike `filter()`, this narrows `required[]` but leaves
     * `properties[discriminatorKey].enum` untouched — the runtime only cares
     * about the op being executed, not what other ops exist.
     *
     * `__required_when` handling depends on which path is taken:
     *  - First early-return (no per-op bindings): the schema is returned
     *    unchanged. The side-channel was not present to begin with, so the
     *    returned schema carries no `__required_when` key.
     *  - Second early-return (bindings present but `required` already empty):
     *    the side-channel is explicitly written back onto the schema. The
     *    `__required_when` key IS present on the returned schema in this case.
     *  - Main path (bindings present and `required` non-empty): `required[]`
     *    is narrowed and `__required_when` is then unset.
     *
     * The middle path therefore leaves the side-channel in place. This is
     * benign — `SchemaValidator` only walks `required` and `properties`, so
     * the orphan key has no functional effect — but it is the one path that
     * does not strip the key. The docblock records this explicitly so the
     * invariant is visible to readers (and to the test suite).
     *
     * @param  array{
     *   type?: string,
     *   properties?: array<string, mixed>|stdClass,
     *   required?: list<string>,
     *   __required_when?: array<string, list<string>>,
     * } $schema
     * @return array{type: string, properties?: array<string, mixed>|stdClass, required: list<string>}
     */
    public static function filterForOperation(array $schema, string $operationName): array
    {
        $requiredWhen = $schema[ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY] ?? [];
        if ($requiredWhen === []) {
            return $schema;
        }

        $properties = self::normaliseProperties($schema['properties'] ?? []);
        $required   = $schema['required'] ?? [];
        if ($required === []) {
            $schema[ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY] = $requiredWhen;
            $schema['properties'] = $properties === [] ? new stdClass() : $properties;
            return $schema;
        }

        $matchesOp = static fn(string $name) => self::bindingIntersectsOp($requiredWhen[$name] ?? null, $operationName);

        // Narrow `properties` first so per-op-bound properties whose binding
        // doesn't include the active op are dropped in lockstep with
        // `required[]` — the runtime validator sees a schema that actually
        // matches the op being executed.
        $filtered = $properties === []
            ? $properties
            : array_filter($properties, $matchesOp, ARRAY_FILTER_USE_KEY);

        $schema['properties'] = $filtered === [] ? new stdClass() : $filtered;
        $schema['required']   = array_values(array_filter($required, $matchesOp));
        unset($schema[ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY]);

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normaliseProperties(mixed $properties): array
    {
        if (is_object($properties)) {
            return (array) $properties;
        }
        return is_array($properties) ? $properties : [];
    }

    /**
     * @param  array<string, mixed> $properties
     * @param  array<string, int>   $allowedOpsSet
     * @return array<string, mixed>
     */
    private static function narrowDiscriminatorEnum(array $properties, string $discriminatorKey, array $allowedOpsSet): array
    {
        if (!isset($properties[$discriminatorKey]['enum'])) {
            return $properties;
        }
        $properties[$discriminatorKey]['enum'] = array_values(array_filter(
            $properties[$discriminatorKey]['enum'],
            static fn($op) => isset($allowedOpsSet[$op]),
        ));
        return $properties;
    }

    /**
     * True when `$binding` is absent (always-keep) or contains at least
     * one op in `$allowedOpsSet`. Shared by `filter()`'s two narrowing
     * passes so the matching rule lives in one place.
     *
     * @param  list<string>|null $binding
     * @param  array<string, int> $allowedOpsSet
     */
    private static function bindingIntersects(?array $binding, array $allowedOpsSet): bool
    {
        if ($binding === null) {
            return true;
        }
        foreach ($binding as $op) {
            if (isset($allowedOpsSet[$op])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Single-op counterpart to {@see self::bindingIntersects()} for
     * `filterForOperation()` — the runtime only cares whether the op
     * being executed intersects the per-property binding.
     *
     * @param  list<string>|null $binding
     */
    private static function bindingIntersectsOp(?array $binding, string $operationName): bool
    {
        if ($binding === null) {
            return true;
        }
        foreach ($binding as $op) {
            if ($op === $operationName) {
                return true;
            }
        }
        return false;
    }
}
