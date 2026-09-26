<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Declares a named operation within a tool class.
 *
 * The orchestrator reads the `discriminatorKey` field from an incoming tool call to
 * select the matching #[ToolOperation] and check whether it is enabled/requires approval.
 *
 * `description` is the LLM-facing text — wire quirks, edge cases, skill pointers.
 * `operatorDescription` (optional) is what the operator UI displays. When omitted,
 * {@see \Spora\Tools\ToolSchemaPresenter::summarize()} falls back to the first
 * sentence of `description`, so existing tools keep rendering as before but stop
 * flooding the operator's tools page with the LLM-targeted prose.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolOperation
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool   $enabledByDefault           = true,
        public readonly bool   $requiresApprovalByDefault = true,
        public readonly string $discriminatorKey = 'action',
        public readonly ?string $operatorDescription = null,
    ) {}
}
