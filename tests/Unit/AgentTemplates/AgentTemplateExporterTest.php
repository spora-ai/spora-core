<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;
use Tests\Fixtures\TestTool;

require_once BASE_PATH . '/tests/Unit/Core/ContainerDefinitionsTest.php';
require_once BASE_PATH . '/tests/Unit/AgentTemplates/AgentTemplateImporterTest.php';

function makeExporter(?PluginLoader $pluginLoader = null): AgentTemplateExporter
{
    return new AgentTemplateExporter($pluginLoader ?? new PluginLoader([]));
}

beforeEach(function (): void {
    $this->userId = bootAuth(bootAuthLayer(), 'template-exporter@example.com');
});

test('export() NEVER includes a settings key at any level', function (): void {
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Export Test',
        'max_steps' => 7,
        'is_active' => true,
    ]);

    $exported = makeExporter()->export($agent);
    $payload = $exported['template']->raw();

    expect($payload)->not->toHaveKey('settings');

    // Also walk tools[] to make sure no per-tool settings sneak in.
    foreach ($payload['tools'] as $tool) {
        expect($tool)->not->toHaveKey('settings');
    }
});

test('export() surfaces the SETTINGS_NOT_EXPORTED_WARNING inline', function (): void {
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'X',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['inline_warning'])->toBe(AgentTemplateImporter::SETTINGS_NOT_EXPORTED_WARNING);
    expect($exported['inline_warning'])->toContain('NOT included');
});

test('export() round-trips an agent created from core-assistant', function (): void {
    $importer = makeImporter(); // helper from AgentTemplateImporterTest.php
    $created = $importer->applyTemplate($this->userId, 'core/core-assistant');

    $exported = makeExporter()->export($created->agent);
    $payload = $exported['template']->raw();

    // Exported id is a slug from the agent name — no `core/` prefix.
    // `core/` is reserved for Spora-shipped templates; user exports
    // must not claim that namespace.
    expect($payload['id'])->toBe('spora-core-agent');
    expect($payload['name'])->toBe('Spora Core Agent');
    expect(count($payload['tools']))->toBe(4);

    // Operations should be present for the auto-approve overrides that
    // the seeder ran. save/delete for memory tools → default_requires_approval=1.
    $agentMemory = collect($payload['tools'])
        ->firstWhere('tool_class', 'Spora\\Tools\\AgentMemoryTool');
    expect($agentMemory)->not->toBeNull();
    $saveOp = collect($agentMemory['operations'])->firstWhere('name', 'save');
    expect($saveOp)->not->toBeNull();
    expect($saveOp['auto_approve'])->toBeFalse();
});

test('export() omits operations that have no explicit override', function (): void {
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Partial',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    // Insert a tool + an override row with BOTH fields null
    // (the "inherit defaults" state).
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Spora\\Tools\\CurrentTimeTool',
        'tool_name'  => 'current_time',
    ]);
    AgentToolOperationOverride::create([
        'agent_id'                  => $agent->id,
        'tool_class'                => 'Spora\\Tools\\CurrentTimeTool',
        'operation'                 => 'now',
        'enabled'                   => null,
        'default_requires_approval' => null,
    ]);

    $exported = makeExporter()->export($agent);
    $payload = $exported['template']->raw();
    $tool = collect($payload['tools'])->firstWhere('tool_class', 'Spora\\Tools\\CurrentTimeTool');
    expect($tool)->not->toBeNull();
    expect($tool['operations'])->toBe([]);
});

test('export() preserves allow_followup → allow_continuation mapping', function (): void {
    $agent = Agent::create([
        'user_id'        => $this->userId,
        'name'           => 'Contin',
        'max_steps'      => 5,
        'allow_followup' => false,
        'is_active'      => true,
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['template']->raw()['agent']['allow_continuation'])->toBeFalse();
});

test('export() derives id from the agent name as a plain slug (no `core/` prefix)', function (): void {
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Research Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['template']->raw()['id'])->toBe('research-agent');
});

test('export() id is just the slug even when the agent name looks namespace-shaped', function (): void {
    // The exporter used to unconditionally prefix `core/`. Operators who
    // want a namespaced id can edit the file before import; the export
    // itself must not claim `core/` (or any other) namespace.
    //
    // Slugify replaces `[^a-z0-9]+` with `-`, so `core/research-agent`
    // becomes `core-research-agent` (the slash collapses to a hyphen).
    // The point of this test is the absence of a `core/` prefix, not
    // preservation of the slash.
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'core/research-agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['template']->raw()['id'])->toBe('core-research-agent');
    // The old buggy behaviour would emit 'core/core-research-agent'.
    expect($exported['template']->raw()['id'])->not->toBe('core/core-research-agent');
});

test('export() id falls back to "exported-agent" when the name slugifies to empty', function (): void {
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => '---',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['template']->raw()['id'])->toBe('exported-agent');
});

test('export() emits required_plugins: [] when the agent uses only core tools', function (): void {
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Core Only',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Spora\\Tools\\CurrentTimeTool',
        'tool_name'  => 'current_time',
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['template']->raw()['required_plugins'])->toBe([]);
});

test('export() lists every owning plugin slug in required_plugins', function (): void {
    // The existing Tests\Fixtures\Plugins\ToolsPlugin fixture contributes
    // TestTool. The empty loader returns null for any other tool_class,
    // so the exporter's required_plugins ends up [tools-plugin] only when
    // the agent uses TestTool.
    $loader = makeToolsPluginLoader();

    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Mixed Tools',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => TestTool::class,
        'tool_name'  => 'test',
    ]);
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Spora\\Tools\\CurrentTimeTool', // not in any plugin fixture
        'tool_name'  => 'current_time',
    ]);

    $exported = makeExporter($loader)->export($agent);

    expect($exported['template']->raw()['required_plugins'])->toBe(['tools-plugin']);
});

test('export() deduplicates required_plugins when two agent_tools share a plugin', function (): void {
    // Same fixture plugin, but with two AgentTool rows for two tools
    // the plugin claims. Exporter must produce ['tools-plugin'] exactly
    // once, not twice.
    $loader = makeToolsPluginLoader();

    // Sanity check: the helper returns null for an unknown class.
    expect($loader->getSlugForToolClass('Spora\\Tools\\NonExistent'))->toBeNull();

    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Single Plugin',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => TestTool::class,
        'tool_name'  => 'test-1',
    ]);
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Spora\\Tools\\CurrentTimeTool',
        'tool_name'  => 'current_time',
    ]);

    $exported = makeExporter($loader)->export($agent);
    expect($exported['template']->raw()['required_plugins'])->toBe(['tools-plugin']);
});

test('export() omits plugin slugs for tool_classes that no loaded plugin owns', function (): void {
    $loader = makeToolsPluginLoader();

    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Core Only',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Spora\\Tools\\CurrentTimeTool', // built-in, no plugin owner
        'tool_name'  => 'current_time',
    ]);

    $exported = makeExporter($loader)->export($agent);
    expect($exported['template']->raw()['required_plugins'])->toBe([]);
});
