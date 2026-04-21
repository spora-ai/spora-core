<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Applied zero-or-more times at class level (repeatable).
 * Each instance declares one named operation within a tool class.
 *
 * The orchestrator resolves the operation name by reading the `discriminatorKey` argument
 * from the incoming tool call. The corresponding #[ToolOperation] is then looked up to
 * determine whether that specific operation is enabled and whether it requires approval.
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
    ) {}
}
