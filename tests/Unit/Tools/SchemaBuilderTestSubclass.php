<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;

#[Tool(name: 'fixture_subclass', description: 'Builder test fixture')]
#[ToolOperation(name: 'run', description: 'Run it')]
#[ToolOperation(name: 'stop', description: 'Stop it')]
#[ToolParameter(name: 'q', type: 'string', description: 'Query', required: true)]
final class SchemaBuilderTestSubclass extends SchemaBuilderTestParent {}
