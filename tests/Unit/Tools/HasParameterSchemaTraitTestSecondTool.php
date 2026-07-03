<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Traits\HasParameterSchema;

#[ToolParameter(name: 'y', type: 'integer', description: 'Y', required: false)]
final class HasParameterSchemaTraitTestSecondTool
{
    use HasParameterSchema;
}
