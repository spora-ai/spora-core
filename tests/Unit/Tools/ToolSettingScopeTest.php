<?php

declare(strict_types=1);

use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolSettingSchema;
use Tests\Fixtures\ScopeFixtureTool;

test('ToolSetting defaults scope to "any" when omitted', function (): void {
    $setting = new ToolSetting(key: 'k', label: 'L', type: 'text');

    expect($setting->scope)->toBe(ToolSetting::SCOPE_ANY);
});

test('ToolSetting accepts scope values "any" | "principal" | "agent"', function (): void {
    $any       = new ToolSetting(key: 'a', label: 'A', type: 'text', scope: 'any');
    $principal = new ToolSetting(key: 'p', label: 'P', type: 'text', scope: 'principal');
    $agent     = new ToolSetting(key: 'g', label: 'G', type: 'text', scope: 'agent');

    expect($any->scope)->toBe('any');
    expect($principal->scope)->toBe('principal');
    expect($agent->scope)->toBe('agent');
});

test('ToolSetting exposes scope constants', function (): void {
    expect(ToolSetting::SCOPE_ANY)->toBe('any');
    expect(ToolSetting::SCOPE_PRINCIPAL)->toBe('principal');
    expect(ToolSetting::SCOPE_AGENT)->toBe('agent');
});

test('ToolSettingSchema::collect preserves the declared scope on each setting', function (): void {
    $collected = ToolSettingSchema::collect(ScopeFixtureTool::class);

    expect($collected)->toHaveCount(3);

    $byKey = [];
    foreach ($collected as $setting) {
        $byKey[$setting->key] = $setting;
    }

    expect($byKey['global_only']->scope)->toBe(ToolSetting::SCOPE_ANY);
    expect($byKey['principal_only']->scope)->toBe(ToolSetting::SCOPE_PRINCIPAL);
    expect($byKey['agent_only']->scope)->toBe(ToolSetting::SCOPE_AGENT);
});

test('SubAgentTool::allowed_target_agents declares scope: principal (locks intra-principal UX)', function (): void {
    $collected = ToolSettingSchema::collect(Spora\Tools\SubAgentTool::class);

    $allowlist = null;
    foreach ($collected as $setting) {
        if ($setting->key === 'allowed_target_agents') {
            $allowlist = $setting;
            break;
        }
    }

    expect($allowlist)->not->toBeNull();
    expect($allowlist->scope)->toBe(ToolSetting::SCOPE_PRINCIPAL);
});
