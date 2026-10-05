<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateScanner;

/**
 * Edge-case coverage tests for AgentTemplateScanner.
 *
 * The main AgentTemplateScannerTest exercises happy paths + JSON failure
 * surfaces. This file focuses on:
 *   - YAML parsing
 *   - Custom-code error templates (via the public scan() API)
 *   - Source labelling per root, and the namespace check it drives
 */
test('scan() parses YAML templates alongside JSON', function (): void {
    $dir = sys_get_temp_dir() . '/spora_tpl_yaml_' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/weather.yaml', <<<'YAML'
id: weather-helper
name: Weather Helper
version: 1.0.0
agent:
  max_steps: 5
  system_prompt: be brief
tools: []
required_plugins: []
metadata:
  category: research
  icon: sun
YAML);

    try {
        $scanner = new AgentTemplateScanner(roots: [['path' => $dir, 'source' => 'core']]);
        $templates = $scanner->scan();

        expect($templates)->toHaveCount(1);
        expect($templates[0]->id())->toBe('weather-helper');
        expect($templates[0]->metadata()['icon'] ?? null)->toBe('sun');
    } finally {
        @unlink($dir . '/weather.yaml');
        @rmdir($dir);
    }
});

test('scan() surfaces a YAML parse error with PARSE_ERROR code', function (): void {
    $dir = sys_get_temp_dir() . '/spora_tpl_yaml_bad_' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/broken.yaml', "id: x\n: invalid yaml here\n  : ::");

    try {
        $scanner = new AgentTemplateScanner(roots: [['path' => $dir, 'source' => 'core']]);
        $templates = $scanner->scan();

        expect($templates)->toHaveCount(1);
        expect($templates[0]->filename())->toBe('broken.yaml');
        $codes = array_column($templates[0]->warnings(), 'code');
        expect($codes)->toContain('PARSE_ERROR');
    } finally {
        @unlink($dir . '/broken.yaml');
        @rmdir($dir);
    }
});

test('scan() reports a core root label and exempts it from the namespace check', function (): void {
    $dir = sys_get_temp_dir() . '/spora_tpl_src_' . uniqid();
    mkdir($dir);
    // A bare id is a namespace mismatch under a plugin root but is fine
    // under `core`: bundled templates predate the namespacing rule and
    // the framework ships `core-assistant.json`, not `core.json`.
    file_put_contents($dir . '/my-bundle.json', json_encode([
        'id' => 'my-bundle',
        'name' => 'My Bundle',
        'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => [],
        'required_plugins' => [],
        'metadata' => ['category' => 'general', 'icon' => 'puzzle'],
    ]));

    try {
        $templates = (new AgentTemplateScanner(roots: [
            ['path' => $dir, 'source' => 'core'],
        ]))->scan();

        expect($templates[0]->source())->toBe('core');
        expect(array_column($templates[0]->warnings(), 'code'))
            ->not->toContain('NAMESPACE_MISMATCH');
    } finally {
        @unlink($dir . '/my-bundle.json');
        @rmdir($dir);
    }
});

test('scan() emits a NAMESPACE_MISMATCH warning when a plugin file id lacks the source prefix', function (): void {
    $dir = sys_get_temp_dir() . '/weather';
    @mkdir($dir, 0777, true);
    $file = $dir . '/broken-' . uniqid() . '.json';
    file_put_contents($file, json_encode([
        'id' => 'unscoped',
        'name' => 'Broken',
        'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => [],
        'required_plugins' => [],
        'metadata' => ['category' => 'general', 'icon' => 'puzzle'],
    ]));

    try {
        $templates = (new AgentTemplateScanner(roots: [
            ['path' => $dir, 'source' => 'weather'],
        ]))->scan();

        expect($templates)->toHaveCount(1);
        $codes = array_column($templates[0]->warnings(), 'code');
        expect($codes)->toContain('NAMESPACE_MISMATCH');
    } finally {
        @unlink($file);
        @rmdir($dir);
    }
});

test('scan() accepts a plugin file id with the matching source prefix (no warning)', function (): void {
    $dir = sys_get_temp_dir() . '/weather';
    @mkdir($dir, 0777, true);
    $file = $dir . '/ok-' . uniqid() . '.json';
    file_put_contents($file, json_encode([
        'id' => 'weather/ok',
        'name' => 'OK',
        'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => [],
        'required_plugins' => [],
        'metadata' => ['category' => 'general', 'icon' => 'puzzle'],
    ]));

    try {
        $templates = (new AgentTemplateScanner(roots: [
            ['path' => $dir, 'source' => 'weather'],
        ]))->scan();

        expect($templates)->toHaveCount(1);
        $codes = array_column($templates[0]->warnings(), 'code');
        expect($codes)->not->toContain('NAMESPACE_MISMATCH');
    } finally {
        @unlink($file);
        @rmdir($dir);
    }
});

/**
 * @param array<string, mixed> $overrides
 */
function writeTemplateFixture(string $dir, string $filename, array $overrides = []): string
{
    $path = $dir . '/' . $filename;
    file_put_contents($path, json_encode(array_merge([
        'name'        => 'Fixture',
        'version'     => '1.0.0',
        'agent'       => ['max_steps' => 5, 'system_prompt' => 'x'],
        'tools'       => [],
        'required_plugins' => [],
        'metadata'    => ['category' => 'general', 'icon' => 'puzzle'],
    ], $overrides)));
    return $path;
}

test('a plugin root labels its templates with the plugin slug', function (): void {
    $dir = sys_get_temp_dir() . '/spora_tpl_plugin_' . uniqid();
    mkdir($dir);
    $file = writeTemplateFixture($dir, 'assistant.json', ['id' => 'memories/assistant']);

    try {
        $templates = (new AgentTemplateScanner(roots: [
            ['path' => $dir, 'source' => 'memories'],
        ]))->scan();

        // Regression: the shipped memories plugin declares `memories/assistant`
        // and must not be warned about just because its directory is called
        // `agent-templates`.
        expect($templates[0]->source())->toBe('memories');
        expect(array_column($templates[0]->warnings(), 'code'))
            ->not->toContain('NAMESPACE_MISMATCH');
    } finally {
        @unlink($file);
        @rmdir($dir);
    }
});

test('project and app roots label their templates with their own source', function (): void {
    $projectDir = sys_get_temp_dir() . '/spora_tpl_project_' . uniqid();
    $appDir     = sys_get_temp_dir() . '/spora_tpl_app_' . uniqid();
    mkdir($projectDir);
    mkdir($appDir);
    $projectFile = writeTemplateFixture($projectDir, 'mine.json', ['id' => 'project/mine']);
    $appFile     = writeTemplateFixture($appDir, 'theirs.json', ['id' => 'app/theirs']);

    try {
        $templates = (new AgentTemplateScanner(roots: [
            ['path' => $projectDir, 'source' => 'project'],
            ['path' => $appDir, 'source' => 'app'],
        ]))->scan();

        $sources = [];
        foreach ($templates as $t) {
            $sources[$t->id()] = $t->source();
        }
        expect($sources)->toBe(['project/mine' => 'project', 'app/theirs' => 'app']);
    } finally {
        @unlink($projectFile);
        @unlink($appFile);
        @rmdir($projectDir);
        @rmdir($appDir);
    }
});

test('two plugin roots shipping the same short id both survive, earlier root first', function (): void {
    // The scanner deliberately does not dedupe: two plugins may ship the
    // same short id, and the first root in priority order is the one
    // AgentTemplateImporter::applyTemplate() resolves. Pin the ordering
    // the container's root order gives us.
    $first  = sys_get_temp_dir() . '/spora_tpl_first_' . uniqid();
    $second = sys_get_temp_dir() . '/spora_tpl_second_' . uniqid();
    mkdir($first);
    mkdir($second);
    $firstFile  = writeTemplateFixture($first, 'dup.json', ['id' => 'dup', 'name' => 'First']);
    $secondFile = writeTemplateFixture($second, 'dup.json', ['id' => 'dup', 'name' => 'Second']);

    try {
        $templates = (new AgentTemplateScanner(roots: [
            ['path' => $first, 'source' => 'alpha'],
            ['path' => $second, 'source' => 'beta'],
        ]))->scan();

        expect($templates)->toHaveCount(2);
        expect($templates[0]->source())->toBe('alpha');
        expect($templates[1]->source())->toBe('beta');
    } finally {
        @unlink($firstFile);
        @unlink($secondFile);
        @rmdir($first);
        @rmdir($second);
    }
});
