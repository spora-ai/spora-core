<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\Attributes\ToolSetting;

/**
 * The oldest ancestor in the tool-setting inheritance chain.
 *
 * Its declarations are the stale ones: the credential is plain text and
 * the note is model-visible. The classes below it narrow both.
 */
#[ToolSetting(key: 'api_key', label: 'API key', type: 'text', description: 'Stale classification')]
#[ToolSetting(key: 'private_note', label: 'Private note', type: 'text', description: 'Stale disclosure', exposeToLlm: true)]
abstract class ToolSettingSchemaTestGrandparent {}
