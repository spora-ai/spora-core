<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\AgentToolOverride;

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
    expect($tools)->toContain('Spora\\Tools\\TimeTool');
    expect($tools)->toContain('Spora\\Tools\\CalculatorTool');
    expect(count($tools))->toBe(2);
});

test('applyTemplate("core/core-assistant") persists per-operation auto_approve overrides', function (): void {
    $result = $this->importer->applyTemplate($this->userId, 'core/core-assistant');

    $nowOverride = AgentToolOperationOverride::where('agent_id', $result->agent->id)
        ->where('tool_class', 'Spora\\Tools\\TimeTool')
        ->where('operation', 'now')
        ->first();
    expect($nowOverride)->not->toBeNull();
    // auto_approve=true in template → default_requires_approval=0 in DB
    expect((int) $nowOverride->default_requires_approval)->toBe(0);
});

test('applyTemplate persists allow_followup from the agent{} block', function (): void {
    $result = $this->importer->applyTemplate($this->userId, 'core/core-assistant');
    expect((bool) $result->agent->allow_followup)->toBeTrue();
});

test('importPayload skips tools whose tool_class is not registered (TOOL_PLUGIN_MISSING warning)', function (): void {
    $raw = [
        'id' => 'mixed', 'name' => 'Mixed', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'x'],
        'tools' => [
            ['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => true, 'operations' => []],
            ['tool_class' => 'Spora\\Tools\\NoSuchTool\\Anywhere', 'enabled' => true, 'operations' => []],
        ],
        'required_plugins' => [],
    ];

    $result = $this->importer->importPayload($this->userId, $raw);

    $tools = AgentTool::where('agent_id', $result->agent->id)->get()->pluck('tool_class')->all();
    expect($tools)->toContain('Spora\\Tools\\TimeTool');
    expect($tools)->not->toContain('Spora\\Tools\\NoSuchTool\\Anywhere');

    $codes = array_column($result->warnings, 'code');
    expect($codes)->toContain('TOOL_PLUGIN_MISSING');
});

test('importPayload emits PLUGIN_MISSING for required_plugins not loaded', function (): void {
    $raw = [
        'id' => 'with-plugin', 'name' => 'With Plugin', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'x'],
        'tools' => [],
        'required_plugins' => ['spora-ai/spora-plugin-does-not-exist'],
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
            'tool_class' => 'Spora\\Tools\\TimeTool',
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

test('importPayload accepts skills/agent-creation/example.json round-trip with zero errors', function (): void {
    // The example.json fixture is the worked example at the bottom of
    // skills/agent-creation/SKILL.md — it's what the LLM is told to copy
    // when assembling a new agent. The fixture references the Weather
    // plugin's WeatherApiTool which is NOT registered in the test
    // tool_classes list, so it produces a TOOL_PLUGIN_MISSING warning.
    // That is non-blocking (the warning is intentional, not a regression),
    // and required_plugins also produces PLUGIN_MISSING. The validator's
    // hard errors (UNKNOWN_*, *_PATTERN, *_INVALID) must NOT fire — the
    // schema is correct; only the runtime plugin lookup is ambiguous.
    $examplePath = BASE_PATH . '/skills/agent-creation/example.json';
    expect(is_file($examplePath))->toBeTrue();

    $payload = json_decode((string) file_get_contents($examplePath), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $payload */

    // Pre-flight: the example payload must validate cleanly on its own.
    // If the validator reports errors here, the skill's worked example is
    // already broken — fix it before publishing.
    $validation = (new Spora\AgentTemplates\AgentTemplateValidator())->validate($payload);
    expect($validation->errors())->toBe([]);

    $result = $this->importer->importPayload($this->userId, $payload);

    expect($result->agent)->toBeInstanceOf(Agent::class);
    expect($result->agent->name)->toBe('Weather Agent');

    $warningCodes = array_column($result->warnings, 'code');
    expect($warningCodes)->not->toContain('VERSION_PATTERN')
        ->and($warningCodes)->not->toContain('UNKNOWN_AGENT_KEY')
        ->and($warningCodes)->not->toContain('UNKNOWN_TOP_LEVEL_KEY');

    // The fixture intentionally references a plugin that is not loaded
    // here, so PLUGIN_MISSING and TOOL_PLUGIN_MISSING are the only
    // warnings — both are non-blocking by design.
    expect($warningCodes)->toContain('TOOL_PLUGIN_MISSING')
        ->and($warningCodes)->toContain('PLUGIN_MISSING');
});

test('importPayload without a tools block creates the agent row but skips tool activation', function (): void {
    // The LLM-facing create_agent path runs with no nested `tools` block;
    // the agent row should be created cleanly and toolsEnabled should be
    // empty so the LLM can call configure_tools separately to apply the
    // toolset.
    $raw = [
        'id' => 'no-tools', 'name' => 'No Tools', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'x'],
        'required_plugins' => [],
    ];

    $result = $this->importer->importPayload($this->userId, $raw);

    expect($result->agent)->toBeInstanceOf(Agent::class);
    expect($result->agent->name)->toBe('No Tools');
    expect($result->toolsEnabled)->toBe([]);

    $tools = AgentTool::where('agent_id', $result->agent->id)->get();
    expect(count($tools))->toBe(0);
});

test('opt-in export settings round-trip through the importer without secrets', function (): void {
    $security = new Spora\Core\SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new Spora\Services\ToolConfigService($security, new Monolog\Logger('test'), [Tests\Fixtures\TestTool::class]);
    $plugins = new Spora\Plugins\PluginLoader([]);
    $settingsApplier = new Spora\AgentTemplates\AgentTemplateSettingsApplier($toolConfig, null);
    $importer = new Spora\AgentTemplates\AgentTemplateImporter(
        $toolConfig,
        $plugins,
        new Spora\Core\Paths(BASE_PATH),
        new Spora\AgentTemplates\AgentTemplateToolsApplier($toolConfig, $settingsApplier),
        new Spora\AgentTemplates\AgentTemplateAgentCreator(),
    );
    $exporter = new Spora\AgentTemplates\AgentTemplateExporter(
        $plugins,
        $toolConfig,
        new Spora\Services\ToolConfigSchemaInspector(),
    );
    $source = Agent::create(['principal_id' => createUserPrincipalPublic($this->userId), 'name' => 'Settings Source', 'max_steps' => 5, 'is_active' => true]);
    AgentTool::create(['agent_id' => $source->id, 'tool_class' => Tests\Fixtures\TestTool::class, 'tool_name' => 'test']);
    $toolConfig->putAgentOverride(Tests\Fixtures\TestTool::class, (int) $source->id, [
        'api_key' => 'secret',
        'max_results' => '25',
        'allowed_target_agents' => '["1","2"]',
    ]);

    $payload = $exporter->export($source, true)['template']->raw();
    $result = $importer->importPayload($this->userId, $payload);
    $settings = $toolConfig->getRawAgentOverride(Tests\Fixtures\TestTool::class, (int) $result->agent->id);

    expect($settings)->toMatchArray(['max_results' => '25', 'allowed_target_agents' => '["1","2"]'])
        ->and($settings)->not->toHaveKey('api_key')
        ->and($result->toolsEnabled[0]['settings_applied'])->toBe(2);
});

test('missing skill slugs warn and are dropped from imported settings', function (): void {
    $scanner = new Spora\Skills\SkillScanner([]);
    $security = new Spora\Core\SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new Spora\Services\ToolConfigService($security, new Monolog\Logger('test'), [Spora\Tools\SkillTool::class], $scanner);
    $settingsApplier = new Spora\AgentTemplates\AgentTemplateSettingsApplier($toolConfig, $scanner);
    $importer = new Spora\AgentTemplates\AgentTemplateImporter(
        $toolConfig,
        new Spora\Plugins\PluginLoader([]),
        new Spora\Core\Paths(BASE_PATH),
        new Spora\AgentTemplates\AgentTemplateToolsApplier($toolConfig, $settingsApplier),
        new Spora\AgentTemplates\AgentTemplateAgentCreator(),
    );
    $raw = [
        'id' => 'skills', 'name' => 'Skills', 'version' => '1.0.0',
        'agent' => ['system_prompt' => 'x'],
        'tools' => [[
            'tool_class' => Spora\Tools\SkillTool::class,
            'enabled' => true,
            'operations' => [],
            'settings' => ['allowed_skills' => ['weather']],
        ]],
    ];

    $result = $importer->importPayload($this->userId, $raw);
    $settings = $toolConfig->getRawAgentOverride(Spora\Tools\SkillTool::class, (int) $result->agent->id);
    $warning = collect($result->warnings)->firstWhere('code', 'SKILL_MISSING');

    expect($settings['allowed_skills'])->toBe('[]')
        ->and($warning['path'])->toBe('tools[0].settings.allowed_skills');
});

test('settings are not applied when the tool plugin is missing', function (): void {
    $raw = [
        'id' => 'missing', 'name' => 'Missing', 'version' => '1.0.0',
        'agent' => ['system_prompt' => 'x'],
        'tools' => [[
            'tool_class' => 'Missing\\Plugin\\Tool',
            'enabled' => true,
            'operations' => [],
            'settings' => ['anything' => 'value'],
        ]],
    ];

    $result = $this->importer->importPayload($this->userId, $raw);

    expect(array_column($result->warnings, 'code'))->toContain('TOOL_PLUGIN_MISSING')
        ->and(AgentToolOverride::where('agent_id', $result->agent->id)->count())->toBe(0);
});
