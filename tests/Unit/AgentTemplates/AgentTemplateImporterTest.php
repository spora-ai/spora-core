<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;

beforeEach(function (): void {
    $this->importer = makeImporter();
    $this->userId = bootAuth(bootAuthLayer(), 'template-importer@example.com');
});

test('applyTemplate("core/core-assistant") creates the Agent and 4 enabled tool rows', function (): void {
    $result = $this->importer->applyTemplate($this->userId, 'core/core-assistant');

    expect($result->agent)->toBeInstanceOf(Agent::class);
    expect((int) $result->agent->user_id)->toBe($this->userId);
    expect($result->agent->name)->toBe('Spora Core Agent');

    $tools = AgentTool::where('agent_id', $result->agent->id)->get()->pluck('tool_class')->all();
    expect($tools)->toContain('Spora\\Tools\\CurrentTimeTool');
    expect($tools)->toContain('Spora\\Tools\\CalculatorTool');
    expect(count($tools))->toBe(2);
});

test('applyTemplate("core/core-assistant") persists per-operation auto_approve overrides', function (): void {
    $result = $this->importer->applyTemplate($this->userId, 'core/core-assistant');

    $nowOverride = AgentToolOperationOverride::where('agent_id', $result->agent->id)
        ->where('tool_class', 'Spora\\Tools\\CurrentTimeTool')
        ->where('operation', 'now')
        ->first();
    expect($nowOverride)->not->toBeNull();
    // auto_approve=true in template → default_requires_approval=0 in DB
    expect((int) $nowOverride->default_requires_approval)->toBe(0);
});

test('applyTemplate maps allow_continuation to allow_followup on the Agent row', function (): void {
    $result = $this->importer->applyTemplate($this->userId, 'core/core-assistant');
    expect((bool) $result->agent->allow_followup)->toBeTrue();
});

test('importPayload skips tools whose tool_class is not registered (TOOL_PLUGIN_MISSING warning)', function (): void {
    $raw = [
        'id' => 'mixed', 'name' => 'Mixed', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'x'],
        'tools' => [
            ['tool_class' => 'Spora\\Tools\\CurrentTimeTool', 'enabled' => true, 'operations' => []],
            ['tool_class' => 'Spora\\Tools\\NoSuchTool\\Anywhere', 'enabled' => true, 'operations' => []],
        ],
        'required_plugins' => [],
    ];

    $result = $this->importer->importPayload($this->userId, $raw);

    $tools = AgentTool::where('agent_id', $result->agent->id)->get()->pluck('tool_class')->all();
    expect($tools)->toContain('Spora\\Tools\\CurrentTimeTool');
    expect($tools)->not->toContain('Spora\\Tools\\NoSuchTool\\Anywhere');

    $codes = array_column($result->warnings, 'code');
    expect($codes)->toContain('TOOL_PLUGIN_MISSING');
});

test('importPayload emits PLUGIN_MISSING for required_plugins not loaded', function (): void {
    $raw = [
        'id' => 'with-plugin', 'name' => 'With Plugin', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'x'],
        'tools' => [],
        'required_plugins' => ['definitely-not-installed-plugin'],
    ];

    $result = $this->importer->importPayload($this->userId, $raw);
    $codes = array_column($result->warnings, 'code');
    expect($codes)->toContain('PLUGIN_MISSING');
});

test('importPayload refuses disabled tools (no row inserted)', function (): void {
    $raw = [
        'id' => 'no-row', 'name' => 'No Row', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'x'],
        'tools' => [[
            'tool_class' => 'Spora\\Tools\\CurrentTimeTool',
            'enabled' => false,
            'operations' => [],
        ]],
        'required_plugins' => [],
    ];

    $result = $this->importer->importPayload($this->userId, $raw);
    $tools = AgentTool::where('agent_id', $result->agent->id)->get();
    expect(count($tools))->toBe(0);
});

test('applyTemplate throws when the template id is unknown', function (): void {
    expect(fn() => $this->importer->applyTemplate($this->userId, 'does-not-exist'))
        ->toThrow(RuntimeException::class);
});
