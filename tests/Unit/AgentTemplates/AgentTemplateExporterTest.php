<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Plugins\PluginLoader;
use Tests\Fixtures\TestTool;

function makeExporter(?PluginLoader $pluginLoader = null): AgentTemplateExporter
{
    return makeExporterWithConfig($pluginLoader)[0];
}

/** @return array{AgentTemplateExporter, Spora\Services\ToolConfigService} */
function makeExporterWithConfig(?PluginLoader $pluginLoader = null): array
{
    $security = new Spora\Core\SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new Spora\Services\ToolConfigService(
        $security,
        new Monolog\Logger('test'),
        [Spora\Tools\TimeTool::class, Spora\Tools\SkillTool::class, TestTool::class],
    );
    return [new AgentTemplateExporter($pluginLoader ?? new PluginLoader([]), $toolConfig), $toolConfig];
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
    expect(count($payload['tools']))->toBe(2);

    // Operations should be present for the auto-approve overrides that
    // the seeder ran. time's `now` operation has auto_approve=true
    // → default_requires_approval=0, so no per-op override row exists and
    // operations[] is empty here. We just verify the core tool is in the
    // exported payload.
    $currentTime = collect($payload['tools'])
        ->firstWhere('tool_class', 'Spora\\Tools\\TimeTool');
    expect($currentTime)->not->toBeNull();
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
        'tool_class' => 'Spora\\Tools\\TimeTool',
        'tool_name'  => 'time',
    ]);
    AgentToolOperationOverride::create([
        'agent_id'                  => $agent->id,
        'tool_class'                => 'Spora\\Tools\\TimeTool',
        'operation'                 => 'now',
        'enabled'                   => null,
        'default_requires_approval' => null,
    ]);

    $exported = makeExporter()->export($agent);
    $payload = $exported['template']->raw();
    $tool = collect($payload['tools'])->firstWhere('tool_class', 'Spora\\Tools\\TimeTool');
    expect($tool)->not->toBeNull();
    expect($tool['operations'])->toBe([]);
});

test('export() persists allow_followup on the agent{} block', function (): void {
    $agent = Agent::create([
        'user_id'        => $this->userId,
        'name'           => 'Contin',
        'max_steps'      => 5,
        'allow_followup' => false,
        'is_active'      => true,
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['template']->raw()['agent']['allow_followup'])->toBeFalse();
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
        'tool_class' => 'Spora\\Tools\\TimeTool',
        'tool_name'  => 'time',
    ]);

    $exported = makeExporter()->export($agent);
    expect($exported['template']->raw()['required_plugins'])->toBe([]);
});

test('export() lists every owning plugin\'s Composer package name in required_plugins', function (): void {
    // The ToolsPlugin fixture ships composer.json#name =
    // 'spora-ai/spora-fixture-tools-plugin' (added in this change).
    // The exporter must emit the package name, NOT the slug — the slug
    // is a directory name and won't resolve against Packagist; only the
    // Composer package name (vendor/name) does.
    $loader = makeToolsPluginLoader();

    // Sanity check the new helper: it reads composer.json from the plugin
    // directory and returns the package name.
    expect($loader->getComposerNameForSlug('tools-plugin'))
        ->toBe('spora-ai/spora-fixture-tools-plugin');

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
        'tool_class' => 'Spora\\Tools\\TimeTool', // not in any plugin fixture
        'tool_name'  => 'time',
    ]);

    $exported = makeExporter($loader)->export($agent);

    expect($exported['template']->raw()['required_plugins'])
        ->toBe(['spora-ai/spora-fixture-tools-plugin']);
});

test('export() deduplicates required_plugins when two agent_tools share a plugin', function (): void {
    $loader = makeToolsPluginLoader();

    // Sanity check on the helper chain: unknown tool → null slug →
    // null package → dropped.
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
        'tool_class' => 'Spora\\Tools\\TimeTool',
        'tool_name'  => 'time',
    ]);

    $exported = makeExporter($loader)->export($agent);
    expect($exported['template']->raw()['required_plugins'])
        ->toBe(['spora-ai/spora-fixture-tools-plugin']);
});

test('export() omits plugins whose composer.json is missing or has no name', function (): void {
    // Exposes the `getComposerNameForSlug()` code path that returns null
    // when the manifest is registered but composer.json is malformed.
    // We don't boot a plugin here because every loadable plugin must
    // declare its class via the PSR-4 manifest autoload; we exercise the
    // null-return branch directly on the helper.
    $tmp = sys_get_temp_dir() . '/spora-plugin-loader-null-' . uniqid();
    mkdir($tmp, 0o755, true);
    file_put_contents($tmp . '/composer.json', json_encode(['type' => 'spora-plugin']));

    $loader = new PluginLoader([]);
    // Inject the directory via reflection — production code paths populate
    // this map during boot(), but for unit-test coverage of the helper's
    // null-return branch we don't need a real plugin instance.
    $ref = new ReflectionClass($loader);
    $prop = $ref->getProperty('pluginDirs');
    $prop->setValue($loader, ['no-name' => $tmp]);

    expect($loader->getComposerNameForSlug('no-name'))->toBeNull();

    @unlink($tmp . '/composer.json');
    @rmdir($tmp);
});

test('PluginLoader::getComposerNameForSlug() returns null for unknown slugs', function (): void {
    $loader = new PluginLoader([]);
    expect($loader->getComposerNameForSlug('does-not-exist'))->toBeNull();
});

test('PluginLoader::getComposerNameForSlug() returns null when composer.json is unreadable', function (): void {
    // No composer.json at the path → null (treated the same as missing).
    $tmp = sys_get_temp_dir() . '/spora-plugin-loader-no-json-' . uniqid();
    mkdir($tmp, 0o755, true);

    $loader = new PluginLoader([]);
    $ref = new ReflectionClass($loader);
    $prop = $ref->getProperty('pluginDirs');
    $prop->setValue($loader, ['no-json' => $tmp]);

    expect($loader->getComposerNameForSlug('no-json'))->toBeNull();

    @rmdir($tmp);
});

test('round-trip imports then exports the bundled core-assistant.json identically', function (): void {
    // Pin both halves of the rename (allow_continuation → allow_followup) at
    // once: the importer reads `allow_followup` from the agent{} block and
    // writes it to the DB column; the exporter reads the DB column and emits
    // `allow_followup` in the agent{} block. A round-trip through both
    // layers must deep-equal on the agent{} block and on the per-tool
    // operations, otherwise one of the two halves has drifted.
    $sourcePath = BASE_PATH . '/agent-templates/core-assistant.json';
    expect(is_file($sourcePath))->toBeTrue();

    $source = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $source */

    $importer = makeImporter();
    $created = $importer->applyTemplate($this->userId, 'core/core-assistant');

    $exported = makeExporter()->export($created->agent);
    $exportedRaw = $exported['template']->raw();

    // The exporter derives `id` from the agent name (slugs to
    // 'spora-core-agent' rather than preserving the source's
    // 'core/core-assistant' namespace), and resets version + required_plugins,
    // so those parts of the payload are NOT expected to match. The agent{}
    // block and tools[].operations[] however must deep-equal — that's what
    // catches a half-finished rename of allow_continuation. Key order is
    // not preserved (the exporter writes keys in code order, the validator
    // accepts them in declaration order), tool order follows the agent_tools
    // primary key, and per-tool operation order follows the override row id,
    // so use loose equality on the agent{} block and sort tools by
    // tool_class (and operations by name) before comparing.
    expect($exportedRaw['agent'])->toEqual($source['agent']);

    $sortByToolClass = static fn(array $a, array $b): int
        => strcmp((string) $a['tool_class'], (string) $b['tool_class']);
    $sortByName = static fn(array $a, array $b): int
        => strcmp((string) $a['name'], (string) $b['name']);

    $exportedTools = $exportedRaw['tools'];
    $sourceTools = $source['tools'];
    usort($exportedTools, $sortByToolClass);
    usort($sourceTools, $sortByToolClass);

    $exportedOps = array_map(
        static function (array $t) use ($sortByName): array {
            $ops = $t['operations'];
            usort($ops, $sortByName);
            return $ops;
        },
        $exportedTools,
    );
    $sourceOps = array_map(
        static function (array $t) use ($sortByName): array {
            $ops = $t['operations'];
            usort($ops, $sortByName);
            return $ops;
        },
        $sourceTools,
    );

    expect($exportedOps)->toEqual($sourceOps);
});

test('export() then importPayload() round-trips on the same loader with zero PLUGIN_MISSING', function (): void {
    // Pin the vendor/name contract end-to-end: the exporter emits a
    // Composer package name, the importer resolves it back to a slug via
    // PluginLoader::getSlugForPackageName(). If either half regressed,
    // a fresh export would re-import with PLUGIN_MISSING for an agent
    // the operator just created on the same instance.
    $loader = makeToolsPluginLoader();

    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Round Trip',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    Spora\Models\AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => TestTool::class,
        'tool_name'  => 'test',
    ]);

    $exported = makeExporter($loader)->export($agent);
    $payload = $exported['template']->raw();

    // Exporter emits Composer package name, not slug.
    expect($payload['required_plugins'])->toBe(['spora-ai/spora-fixture-tools-plugin']);

    // Re-import through the operator-upload path on the SAME loader:
    // the validator must accept the package name, the importer must
    // resolve it to 'tools-plugin', and no PLUGIN_MISSING warning fires.
    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    $toolConfig = new Spora\Services\ToolConfigService(
        $security,
        $logger,
        [Spora\Tools\TimeTool::class, Spora\Tools\CalculatorTool::class, TestTool::class],
    );
    $paths = new Spora\Core\Paths(BASE_PATH);
    $importer = new AgentTemplateImporter($toolConfig, $loader, $paths);

    $validation = (new Spora\AgentTemplates\AgentTemplateValidator())->validate($payload);
    expect($validation->errors())->toBe([]);

    $result = $importer->importPayload($this->userId, $payload);
    $codes = array_column($result->warnings, 'code');
    expect($codes)->not->toContain('PLUGIN_MISSING');
});

test('export() opt-in includes only non-secret non-empty agent overrides and inline info', function (): void {
    $agent = Agent::create([
        'user_id' => $this->userId,
        'name' => 'Settings Export',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    Spora\Models\AgentTool::create([
        'agent_id' => $agent->id,
        'tool_class' => TestTool::class,
        'tool_name' => 'test',
    ]);
    [$exporter, $toolConfig] = makeExporterWithConfig(makeToolsPluginLoader());
    $toolConfig->putAgentOverride(TestTool::class, (int) $agent->id, [
        'api_key' => 'secret',
        'max_results' => '25',
        'custom_field' => '',
        'allowed_target_agents' => null,
    ]);

    $exported = $exporter->export($agent, true);
    $tool = collect($exported['template']->raw()['tools'])->firstWhere('tool_class', TestTool::class);

    expect($tool['settings'])->toBe(['max_results' => '25'])
        ->and($tool['settings'])->not->toHaveKey('api_key')
        ->and($exported)->toHaveKey('inline_info')
        ->and($exported['inline_info'])->toContain(TestTool::class);
});

test('export() default omits settings and opt-in omits inline info when no exportable values exist', function (): void {
    $agent = Agent::create([
        'user_id' => $this->userId,
        'name' => 'No Settings Export',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    Spora\Models\AgentTool::create([
        'agent_id' => $agent->id,
        'tool_class' => TestTool::class,
        'tool_name' => 'test',
    ]);
    [$exporter, $toolConfig] = makeExporterWithConfig();
    $toolConfig->putAgentOverride(TestTool::class, (int) $agent->id, ['api_key' => 'secret']);

    $default = $exporter->export($agent);
    $optIn = $exporter->export($agent, true);

    expect($default['template']->raw()['tools'][0])->not->toHaveKey('settings')
        ->and($optIn['template']->raw()['tools'][0])->not->toHaveKey('settings')
        ->and($optIn)->not->toHaveKey('inline_info');
});
