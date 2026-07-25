<?php

declare(strict_types=1);

use Spora\Services\ToolConfigSchemaInspector;
use Spora\Skills\Skill;

/**
 * Build an inspector with a stub `skillsByName` map for `resolveAs: 'skill'`
 * tests, plus a fake `SkillScanner` for the ToolConfigService seam.
 */
function inspectorWithSkills(array $skills): ToolConfigSchemaInspector
{
    $map = [];
    foreach ($skills as $s) {
        $map[$s->name()] = $s;
    }
    return new ToolConfigSchemaInspector($map);
}

function skillForTest(string $name, string $description): Skill
{
    return new Skill(
        frontmatter: ['name' => $name, 'description' => $description],
        body: 'body',
        dir: '/tmp/' . $name,
        files: [],
    );
}

test('normalizeMultiSelectValues with resolveAs=agent coerces to int[] (HandoverTool default)', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $normalized = $inspector->normalizeMultiSelectValues(
        Spora\Tools\HandoverTool::class,
        ['allowed_target_agents' => '["5", "1"]'],
    );

    expect($normalized['allowed_target_agents'])->toBe([5, 1]);
});

test('normalizeMultiSelectValues with resolveAs=skill coerces to string[] of validated slugs', function (): void {
    $inspector = inspectorWithSkills([
        skillForTest('git', 'Git skill.'),
    ]);

    $normalized = $inspector->normalizeMultiSelectValues(
        Spora\Tools\SkillTool::class,
        // "BAD--NAME" lowercases to "bad--name" which violates the no-consecutive-hyphens rule;
        // "  " normalises to empty and is dropped; 42 lowercases to "42" which is a valid
        // per-spec slug (the spec allows purely-numeric names); "git" is deduped; "weather" survives.
        ['allowed_skills' => '["git", "BAD--NAME", "  ", 42, "git", "weather"]'],
    );

    expect($normalized['allowed_skills'])->toBe(['git', '42', 'weather']);
});

test('normalizeMultiSelectValues with resolveAs=agent (HandoverTool default) coerces to int[]', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    // resolveAs defaults to 'agent' on HandoverTool; strings round-trip through
    // int casting. The `raw` branch is reserved for tools that explicitly
    // declare resolveAs='raw' — none currently do, so it is covered separately
    // by the SkillTool inspector tests below.
    $normalized = $inspector->normalizeMultiSelectValues(
        Spora\Tools\HandoverTool::class,
        ['allowed_target_agents' => ['5', '1']],
    );

    expect($normalized['allowed_target_agents'])->toBe([5, 1]);
});

test('getLlmToolSettings with resolveAs=skill returns "name: short description" entries', function (): void {
    $inspector = inspectorWithSkills([
        skillForTest('git', 'Guides the agent through safe git operations, commit hygiene, and PR conventions.'),
        skillForTest('pdf', 'Extract PDF text, fill forms, merge files.'),
    ]);

    $out = $inspector->getLlmToolSettings(
        Spora\Tools\SkillTool::class,
        ['allowed_skills' => ['git', 'pdf']],
    );

    $values = $out['allowed_skills']['value'];
    expect($values)->toHaveCount(2)
        ->and($values[0])->toStartWith('git: ')
        ->and($values[1])->toStartWith('pdf: ');
});

test('getLlmToolSettings truncates long descriptions to 80 chars', function (): void {
    $long = str_repeat('a', 200);
    $inspector = inspectorWithSkills([skillForTest('long', $long)]);

    $out = $inspector->getLlmToolSettings(
        Spora\Tools\SkillTool::class,
        ['allowed_skills' => ['long']],
    );

    $value = $out['allowed_skills']['value'][0];
    // "long: " (6 chars) + 77 chars of 'a' + "..."
    expect($value)->toHaveLength(6 + 77 + 3);
});

test('getLlmToolSettings silently drops slugs that no longer exist on disk', function (): void {
    $inspector = inspectorWithSkills([
        skillForTest('git', 'Git skill.'),
    ]);

    $out = $inspector->getLlmToolSettings(
        Spora\Tools\SkillTool::class,
        ['allowed_skills' => ['git', 'deleted-skill']],
    );

    expect($out['allowed_skills']['value'])->toHaveCount(1);
});

test('getLlmToolSettings with resolveAs=agent still resolves to "Name (#id)" strings (default behaviour)', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $out = $inspector->getLlmToolSettings(
        Spora\Tools\HandoverTool::class,
        ['allowed_target_agents' => [1, 2]],
        userId: 1,
    );

    $values = $out['allowed_target_agents']['value'];
    // Without DB lookup (userId scoping, no agents seeded in this test)
    // the values fall back to "#1", "#2".
    expect($values)->toBe(['#1', '#2']);
});
