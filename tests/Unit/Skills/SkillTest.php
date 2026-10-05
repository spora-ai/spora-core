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
        ->and($skill->declaredToolNames())->toBe([])
        ->and($skill->body())->toBe('')
        ->and($skill->bodyBytes())->toBe(0)
        ->and($skill->files())->toBe([])
        ->and($skill->source())->toBeNull()
        ->and($skill->hasWarnings())->toBeFalse();
});

test('Skill::declaredToolNames parses the declaration and keeps the raw string', function (): void {
    $skill = new Skill(
        frontmatter: [
            'name'          => 'x',
            'description'   => 'y',
            'allowed-tools' => "read_url  agent\nread_url",
        ],
        body: '',
        dir: '/tmp/x',
    );

    expect($skill->allowedTools())->toBe("read_url  agent\nread_url")
        ->and($skill->declaredToolNames())->toBe(['read_url', 'agent']);
});

test('Skill::declaredToolNames drops entries that are not tool names', function (): void {
    // A skill with a malformed entry is already reported by the validator; the
    // list a consumer compares against installed tools must not carry an FQCN.
    $skill = new Skill(
        frontmatter: [
            'name'          => 'x',
            'description'   => 'y',
            'allowed-tools' => 'Spora\Tools\MediaTool read_url',
        ],
        body: '',
        dir: '/tmp/x',
    );

    expect($skill->declaredToolNames())->toBe(['read_url']);
});

test('Skill::declaredToolNames is empty for a non-string or blank declaration', function (): void {
    $notAString = new Skill(
        frontmatter: ['name' => 'x', 'allowed-tools' => ['read_url']],
        body: '',
        dir: '/tmp/x',
    );
    $blank = new Skill(
        frontmatter: ['name' => 'x', 'allowed-tools' => '   '],
        body: '',
        dir: '/tmp/x',
    );

    // The raw accessor only rejects an empty string, so a blank one comes back
    // verbatim; it is the parser that has nothing to report.
    expect($notAString->allowedTools())->toBeNull()
        ->and($notAString->declaredToolNames())->toBe([])
        ->and($blank->allowedTools())->toBe('   ')
        ->and($blank->declaredToolNames())->toBe([]);
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
