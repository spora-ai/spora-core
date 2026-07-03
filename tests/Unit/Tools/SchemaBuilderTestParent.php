<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\ToolParameter;

#[ToolParameter(name: 'inherited', type: 'string', description: 'From parent', required: false)]
abstract class SchemaBuilderTestParent {}
