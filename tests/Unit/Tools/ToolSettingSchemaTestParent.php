<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\ToolSetting;

/**
 * The nearest ancestor: it reclassifies the inherited credential as a
 * password and withholds the inherited note from the model.
 */
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password', description: 'Credential')]
#[ToolSetting(key: 'private_note', label: 'Private note', type: 'text', description: 'Operator-only', exposeToLlm: false)]
abstract class ToolSettingSchemaTestParent extends ToolSettingSchemaTestGrandparent {}
