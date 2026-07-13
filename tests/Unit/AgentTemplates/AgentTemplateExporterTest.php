<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;

function makeExporter(): AgentTemplateExporter
{
    return new AgentTemplateExporter();
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

    expect($payload['id'])->toBe('core/spora-core-agent');
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
