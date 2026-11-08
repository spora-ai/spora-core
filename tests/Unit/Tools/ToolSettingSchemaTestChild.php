<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\ToolSetting;

/**
 * The concrete tool: it inherits both keys from the parent and declares
 * one of its own.
 */
#[ToolSetting(key: 'endpoint', label: 'Endpoint', type: 'text', description: 'Own declaration')]
final class ToolSettingSchemaTestChild extends ToolSettingSchemaTestParent {}
