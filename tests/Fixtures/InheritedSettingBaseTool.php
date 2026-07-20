<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\ToolSetting;

#[ToolSetting(key: 'parent_toggle', label: 'Parent Toggle', type: 'toggle', default: false)]
#[ToolSetting(key: 'parent_secret', label: 'Parent Secret', type: 'password', default: null)]
#[ToolSetting(key: 'parent_required', label: 'Parent Required', type: 'text', required: true)]
#[ToolSetting(key: 'parent_picks', label: 'Parent Picks', type: 'multi-select', exposeToLlm: true)]
#[ToolSetting(key: 'parent_visible', label: 'Parent Visible', type: 'text', exposeToLlm: true)]
#[ToolSetting(key: 'parent_with_default', label: 'Parent With Default', type: 'text', default: 'inherited')]
#[ToolSetting(key: 'shared_key', label: 'Parent Shared', type: 'text', default: 'parent-default')]
abstract class InheritedSettingBaseTool {}
