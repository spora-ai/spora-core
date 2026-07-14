<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Core\Paths;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;

function makeImporter(): AgentTemplateImporter
{
    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    // Mirror the 'tool_classes' config from ContainerDefinitions so the
    // importer's plugin-missing detection sees the core tools.
    $toolClasses = [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\AgentMemoryTool::class,
        Spora\Tools\GlobalMemoryTool::class,
        Spora\Tools\ReadUrlTool::class,
        Spora\Tools\UserInfoTool::class,
        Spora\Tools\HandoverTool::class,
    ];
    $toolConfig = new ToolConfigService($security, $logger, $toolClasses);
    // PluginLoader without directories boots an empty loader; tests
    // exercise the tool-class lookup path that doesn't depend on plugins.
    $plugins = new PluginLoader([]);
    $paths = new Paths(BASE_PATH);

    return new AgentTemplateImporter($toolConfig, $plugins, $paths);
}

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
    expect($tools)->toContain('Spora\\Tools\\AgentMemoryTool');
    expect($tools)->toContain('Spora\\Tools\\GlobalMemoryTool');
    expect(count($tools))->toBe(4);
});

test('applyTemplate("core/core-assistant") persists per-operation auto_approve overrides', function (): void {
    $result = $this->importer->applyTemplate($this->userId, 'core/core-assistant');

    $saveOverride = AgentToolOperationOverride::where('agent_id', $result->agent->id)
        ->where('tool_class', 'Spora\\Tools\\AgentMemoryTool')
        ->where('operation', 'save')
        ->first();
    expect($saveOverride)->not->toBeNull();
    // auto_approve=false in template → default_requires_approval=1 in DB
    expect((int) $saveOverride->default_requires_approval)->toBe(1);

    $listOverride = AgentToolOperationOverride::where('agent_id', $result->agent->id)
        ->where('tool_class', 'Spora\\Tools\\AgentMemoryTool')
        ->where('operation', 'list')
        ->first();
    expect($listOverride)->not->toBeNull();
    // auto_approve=true in template → default_requires_approval=0 in DB
    expect((int) $listOverride->default_requires_approval)->toBe(0);
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
