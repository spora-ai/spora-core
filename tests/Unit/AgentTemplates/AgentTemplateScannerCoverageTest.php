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
 *   - Source resolution for the `core` slug and the per-directory fallback
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
        $scanner = new AgentTemplateScanner(directories: [$dir]);
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
        $scanner = new AgentTemplateScanner(directories: [$dir]);
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

test('scan() marks a file whose basename matches the core slugs list as source=core', function (): void {
    $dir = sys_get_temp_dir() . '/spora_tpl_src_' . uniqid();
    mkdir($dir);
    // The scanner resolves `core` by stripping the extension and checking
    // against $coreSlugs. Passing `coreSlugs: ['my-bundle']` here keeps
    // the test deterministic without depending on the framework's
    // bundled core-assistant.json.
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
        $scanner = new AgentTemplateScanner(
            directories: [$dir],
            coreSlugs: ['my-bundle'],
        );
        $templates = $scanner->scan();

        expect($templates[0]->source())->toBe('core');
    } finally {
        @unlink($dir . '/my-bundle.json');
        @rmdir($dir);
    }
});
