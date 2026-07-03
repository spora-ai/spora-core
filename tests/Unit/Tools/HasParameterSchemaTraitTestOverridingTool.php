<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Traits\HasParameterSchema;

final class HasParameterSchemaTraitTestOverridingTool
{
    use HasParameterSchema;

    public function getParametersSchema(): array
    {
        return ['custom' => true];
    }
}
