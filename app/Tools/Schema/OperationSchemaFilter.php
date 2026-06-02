<?php

declare(strict_types=1);

namespace Spora\Tools\Schema;

use stdClass;

/**
 * Narrows a tool's parameter schema to only advertise the operations the
 * current agent is allowed to invoke.
 *
 * The Orchestrator builds the LLM function-calling payload per agent. For
 * multi-operation tools, the discriminator property's `enum` must be filtered
 * to the subset of operations enabled for the agent (via #[ToolOperation]
 * defaults plus AgentToolOperationOverride rows). Operations the agent cannot
 * invoke must not appear in the schema, otherwise the LLM may attempt them.
 *
 * Extracted from Orchestrator::filterSchemaForOperations so the logic is
 * testable in isolation. The Orchestrator passes the live discriminator key —
 * read from the tool's #[ToolOperation] declarations — so tools that use a
 * non-default key (e.g. WorldNewsApiTool uses 'operation') are filtered
 * correctly.
 */
final class OperationSchemaFilter
{
    /**
     * @param  array{type?: string, properties?: array<string, mixed>|stdClass, required?: list<string>} $schema
     * @param  list<string> $allowedOps      Operation names the agent may invoke.
     * @param  string       $discriminatorKey The property name in the schema whose `enum` lists operation names.
     * @return array{type: "object", properties: stdClass|array<string, mixed>, required: list<string>}
     */
    public static function filter(array $schema, array $allowedOps, string $discriminatorKey = 'action'): array
    {
        $allowedOpsSet = array_flip($allowedOps);

        // properties may be a stdClass (from json_decode('{}')) or an array.
        $properties = $schema['properties'] ?? [];
        if (is_object($properties)) {
            $properties = (array) $properties;
        }

        if (isset($properties[$discriminatorKey]['enum'])) {
            $properties[$discriminatorKey]['enum'] = array_values(array_filter(
                $properties[$discriminatorKey]['enum'],
                static fn($op) => isset($allowedOpsSet[$op]),
            ));
        }
        // Operation-specific params are kept — the LLM only calls allowed ops anyway.

        $schema['properties'] = $properties === [] ? new stdClass() : $properties;
        $schema['type']       = $schema['type'] ?? 'object';
        $schema['required']   = $schema['required'] ?? [];

        return $schema;
    }
}
