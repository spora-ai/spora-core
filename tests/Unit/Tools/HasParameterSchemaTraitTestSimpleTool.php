<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Traits\HasParameterSchema;

#[ToolParameter(name: 'x', type: 'string', description: 'X', required: true)]
final class HasParameterSchemaTraitTestSimpleTool
{
    use HasParameterSchema;
}
