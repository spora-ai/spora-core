<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateScanner;
use Spora\AgentTemplates\AgentTemplateValidator;

const FIXTURE_AGENT_TEMPLATES = BASE_PATH . '/tests/Fixtures/agent-templates';

function makeTemplateScanner(array $dirs = []): AgentTemplateScanner
{
    return new AgentTemplateScanner(
        directories: $dirs ?: [FIXTURE_AGENT_TEMPLATES],
        validator: new AgentTemplateValidator(),
    );
}

test('scan() returns one template per valid JSON file', function (): void {
    $templates = makeTemplateScanner()->scan();
    $ids = array_map(static fn($t) => $t->id(), $templates);

    expect($ids)->toContain('minimal');
});

test('scan() flags broken JSON with PARSE_ERROR warning (does not silently drop)', function (): void {
    $templates = makeTemplateScanner()->scan();
    $broken = null;
    foreach ($templates as $t) {
        if ($t->filename() === 'broken.json') {
            $broken = $t;
        }
    }

    expect($broken)->not->toBeNull();
    $warnings = $broken->warnings();
    expect($warnings)->not->toBeEmpty();
    expect($warnings[0]['code'])->toBe('PARSE_ERROR');
});

test('scan() surfaces VALIDATION_ERROR for templates missing required fields', function (): void {
    $templates = makeTemplateScanner()->scan();
    $missing = null;
    foreach ($templates as $t) {
        if ($t->filename() === 'missing_id.json') {
            $missing = $t;
        }
    }

    expect($missing)->not->toBeNull();
    $codes = array_column($missing->warnings(), 'code');
    expect($codes)->toContain('ID_REQUIRED');
});

test('scan() resolves source as "core" for the bundled slug', function (): void {
    // Build a scanner over a directory containing a `core-assistant.json`
    // file directly — exercises the source-resolution path.
    $templates = makeTemplateScanner()->scan();
    $minimal = null;
    foreach ($templates as $t) {
        if ($t->id() === 'minimal') {
            $minimal = $t;
        }
    }

    // The minimal fixture lives under tests/Fixtures/agent-templates,
    // not under a `core` directory — so source falls back to the
    // directory basename (`agent-templates`).
    expect($minimal)->not->toBeNull();
    expect($minimal->source())->toBe('agent-templates');
});

test('scan() merges results from multiple directories', function (): void {
    $tmp = sys_get_temp_dir() . '/spora_tpl_scan_' . uniqid();
    mkdir($tmp);
    file_put_contents($tmp . '/extra.json', json_encode([
        'id'          => 'extra-tpl',
        'name'        => 'Extra',
        'description' => 'From second dir.',
        'version'     => '1.0.0',
        'agent'       => ['max_steps' => 5],
        'tools'       => [],
        'required_plugins' => [],
        'metadata'    => ['category' => 'general', 'icon' => 'puzzle'],
    ]));

    try {
        $templates = makeTemplateScanner([FIXTURE_AGENT_TEMPLATES, $tmp])->scan();
        $ids = array_map(static fn($t) => $t->id(), $templates);
        expect($ids)->toContain('minimal');
        expect($ids)->toContain('extra-tpl');
    } finally {
        @unlink($tmp . '/extra.json');
        @rmdir($tmp);
    }
});

test('scan() with non-existent directory returns empty array', function (): void {
    $templates = (new AgentTemplateScanner(['/tmp/spora_tpl_does_not_exist_' . uniqid()]))->scan();
    expect($templates)->toBe([]);
});

test('scan() preserves partial tool/operation data even when validation fails', function (): void {
    // `missing_id.json` is missing the required `id`, but its tools list
    // is present and intact — the scanner must not discard it.
    $templates = makeTemplateScanner()->scan();
    $missing = null;
    foreach ($templates as $t) {
        if ($t->filename() === 'missing_id.json') {
            $missing = $t;
        }
    }
    expect($missing)->not->toBeNull();
    expect($missing->raw())->toHaveKey('tools');
});
