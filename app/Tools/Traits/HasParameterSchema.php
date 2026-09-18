<?php

declare(strict_types=1);

namespace Spora\Tools\Traits;

use Spora\Tools\Schema\ToolParameterSchemaBuilder;
use stdClass;

/**
 * One-line opt-in for attribute-driven parameter schema generation.
 *
 * Satisfies ToolInterface::getParametersSchema() by delegating to the builder
 * with the calling instance. A class may override the method to customise the
 * generated schema; the trait method is not declared `final`.
 *
 * Tools maintained inside app/Tools/ should prefer extending AbstractTool, which
 * composes this trait alongside HasOperations. This trait is the escape hatch
 * for plugin tools that already extend a third-party base class.
 */
trait HasParameterSchema
{
    /**
     * @return array{
     *   type: "object",
     *   properties: array<string, array<string, mixed>>|stdClass,
     *   required: list<string>
     * }
     */
    public function getParametersSchema(): array
    {
        return ToolParameterSchemaBuilder::build($this);
    }

    /**
     * LLM-facing variant of {@see self::getParametersSchema()} that lets the
     * schema builder populate `#[ToolParameter(enumSource: …)]` properties
     * with runtime-resolved agent ids + description suffixes.
     *
     * Runtime validators (TickPhaseRunner, ToolCallExecutor,
     * ToolCallSerializer) call {@see self::getParametersSchema()} directly —
     * they don't need the LLM-side enrichment and shouldn't pay for it.
     * Only ToolDefinitionBuilder threads these maps through, since it is the
     * one consumer that builds the per-tick LLM payload.
     *
     * @param  array<string, list<int|string>> $enumSourceValues  setting key => resolved ids
     * @param  array<string, list<string>>     $enumSourceLabels  setting key => resolved "Name (#id)" strings
     * @return array{
     *   type: "object",
     *   properties: array<string, array<string, mixed>>|stdClass,
     *   required: list<string>
     * }
     */
    public function getLlmParametersSchema(array $enumSourceValues = [], array $enumSourceLabels = []): array
    {
        return ToolParameterSchemaBuilder::build($this, $enumSourceValues, $enumSourceLabels);
    }
}
