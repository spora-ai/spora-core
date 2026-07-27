<?php

declare(strict_types=1);

use Spora\Skills\Exceptions\SkillNotFoundException;
use Spora\Skills\Skill;

test('Skill accessors fall back safely on missing or malformed fields', function (): void {
    $skill = new Skill(
        frontmatter: [],
        body: '',
        dir: '/tmp/missing',
        files: [],
    );

    expect($skill->name())->toBe('')
        ->and($skill->description())->toBe('')
        ->and($skill->license())->toBeNull()
        ->and($skill->compatibility())->toBeNull()
        ->and($skill->metadata())->toBe([])
        ->and($skill->allowedTools())->toBeNull()
        ->and($skill->body())->toBe('')
        ->and($skill->bodyBytes())->toBe(0)
        ->and($skill->files())->toBe([])
        ->and($skill->source())->toBeNull()
        ->and($skill->hasWarnings())->toBeFalse();
});

test('Skill filters metadata to string keys and string values', function (): void {
    $skill = new Skill(
        frontmatter: [
            'name'        => 'x',
            'description' => 'y',
            'metadata'    => [
                'author'  => 'spora',
                'numeric' => 42,
                'list'    => ['a', 'b'],
            ],
        ],
        body: '',
        dir: '/tmp/x',
        files: [],
    );

    expect($skill->metadata())->toBe(['author' => 'spora']);
});

test('Skill::addWarning + hasWarnings round-trip', function (): void {
    $skill = new Skill(frontmatter: ['name' => 'x'], body: '', dir: '/tmp/x', files: []);

    expect($skill->hasWarnings())->toBeFalse();
    $skill->addWarning([
        'code'     => 'TEST_WARNING',
        'severity' => 'warning',
        'message'  => 'Hello.',
    ]);
    expect($skill->hasWarnings())->toBeTrue();
    expect($skill->warnings())->toHaveCount(1);
});

test('Skill::resolveFilePath strips a leading slash and falls back to the entry file', function (): void {
    $skill = new Skill(
        frontmatter: ['name' => 'x'],
        body: '',
        dir: '/tmp/x',
        files: [
            ['path' => 'SKILL.md',     'bytes' => 100],
            ['path' => 'examples.md',  'bytes' => 200],
        ],
    );

    expect($skill->resolveFilePath('examples.md'))->toBe('/tmp/x/examples.md')
        ->and($skill->resolveFilePath('/SKILL.md'))->toBe('/tmp/x/SKILL.md')
        ->and($skill->resolveFilePath(''))->toBe('/tmp/x/SKILL.md');
});

test('Skill::resolveFilePath raises SkillNotFoundException for files outside the listing', function (): void {
    $skill = new Skill(
        frontmatter: ['name' => 'x'],
        body: '',
        dir: '/tmp/x',
        files: [
            ['path' => 'SKILL.md', 'bytes' => 100],
        ],
    );

    expect(fn() => $skill->resolveFilePath('references/REF.md'))
        ->toThrow(SkillNotFoundException::class);
});
