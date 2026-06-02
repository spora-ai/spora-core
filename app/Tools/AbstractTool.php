<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Tools\Traits\HasOperations;
use Spora\Tools\Traits\HasParameterSchema;

/**
 * Canonical base for built-in tools. Composes the two opt-in traits the
 * authoring conventions assume:
 *
 *   - HasOperations       — per-operation dispatch + override resolution
 *   - HasParameterSchema  — getParametersSchema() generated from #[ToolParameter]
 *                            (and synthesized `action` enum from #[ToolOperation])
 *
 * No constructor is declared so subclasses keep full control of their DI
 * signatures (no-arg tools like CurrentTimeTool, multi-dep tools like EmailTool).
 *
 * Tools must still implement execute() and describeAction() themselves.
 *
 * Plugin tools that already extend a third-party base class can `use HasParameterSchema;`
 * and `use HasOperations;` directly instead of extending this class.
 */
abstract class AbstractTool implements ToolInterface
{
    use HasOperations;
    use HasParameterSchema;
}
