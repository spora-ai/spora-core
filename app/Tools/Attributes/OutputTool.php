<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Applied at class level on OutputToolInterface implementors.
 * Declares the class-level approval default.
 *
 * The Orchestrator checks this after intercepting an OutputTool call:
 *   1. Read agent_tools.auto_approve for this tool+agent (the per-agent override).
 *   2. If NULL (not explicitly set), fall back to this attribute's $requiresApproval.
 *   3. If true  → pause, serialize AgentState, set PENDING_APPROVAL.
 *      If false → execute immediately without human review.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class OutputTool
{
    public function __construct(
        public readonly bool $requiresApproval = true,
    ) {}
}
