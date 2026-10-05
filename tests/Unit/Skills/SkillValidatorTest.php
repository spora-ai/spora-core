<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Services\ToolConfigNameResolver;
use Spora\Skills\SkillValidator;
use Spora\Skills\ValidationResult;
use Spora\Tools\CalculatorTool;
use Spora\Tools\TimeTool;

function validateSkill(array $frontmatter, ?string $body = null, ?string $parentDirName = null): ValidationResult
{
    return (new SkillValidator())->validate($frontmatter, $body, $parentDirName);
}

/**
 * A resolver over real core tool classes, so a declared name is answered by an
 * actual `#[Tool(name:)]` attribute rather than by a fixture the test registers
 * with the same pattern production uses.
 */
function coreToolNameResolver(): ToolConfigNameResolver
{
    return new ToolConfigNameResolver(new NullLogger(), [CalculatorTool::class, TimeTool::class]);
}

function validateSkillResolving(array $frontmatter): ValidationResult
{
    return (new SkillValidator(coreToolNameResolver()))->validate($frontmatter);
}

function messagesFor(string $code, array $entries): string
{
    $messages = [];
    foreach ($entries as $entry) {
        if ($entry['code'] === $code) {
            $messages[] = $entry['message'];
        }
    }

    return implode("\n", $messages);
}

test('validate() returns valid result for a minimal correct frontmatter', function (): void {
    $result = validateSkill(['name' => 'foo', 'description' => 'A skill.']);

    expect($result->isValid())->toBeTrue()
        ->and($result->errors())->toBe([])
        ->and($result->warnings())->toBe([]);
});

test('validate() reports EMPTY_FRONTMATTER on empty input', function (): void {
    $result = validateSkill([]);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('EMPTY_FRONTMATTER');
});

test('validate() reports NAME_REQUIRED when name is missing', function (): void {
    $result = validateSkill(['description' => 'No name.']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('NAME_REQUIRED');
});

test('validate() rejects names with consecutive hyphens', function (): void {
    $result = validateSkill(['name' => 'foo--bar', 'description' => 'Bad.']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('NAME_CONSECUTIVE_HYPHEN');
});

test('validate() rejects names with leading hyphen', function (): void {
    $result = validateSkill(['name' => '-foo', 'description' => 'Bad.']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('NAME_PATTERN');
});

test('validate() rejects names with trailing hyphen', function (): void {
    $result = validateSkill(['name' => 'foo-', 'description' => 'Bad.']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('NAME_PATTERN');
});

test('validate() rejects uppercase names', function (): void {
    $result = validateSkill(['name' => 'Foo', 'description' => 'Bad.']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('NAME_PATTERN');
});

test('validate() rejects names over 64 chars', function (): void {
    $long = str_repeat('a', 65);
    $result = validateSkill(['name' => $long, 'description' => 'Bad.']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('NAME_PATTERN');
});

test('validate() enforces name == parent directory name', function (): void {
    $result = validateSkill(['name' => 'time-arithmetic', 'description' => 'X.'], null, 'git');

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('NAME_DIR_MISMATCH');
});

test('validate() reports DESCRIPTION_REQUIRED when description is missing', function (): void {
    $result = validateSkill(['name' => 'foo']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('DESCRIPTION_REQUIRED');
});

test('validate() rejects empty description', function (): void {
    $result = validateSkill(['name' => 'foo', 'description' => '']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('DESCRIPTION_INVALID');
});

test('validate() rejects description over 1024 chars', function (): void {
    $result = validateSkill(['name' => 'foo', 'description' => str_repeat('a', 1100)]);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('DESCRIPTION_TOO_LONG');
});

test('validate() reports UNKNOWN_TOP_LEVEL_KEY for non-spec fields', function (): void {
    $result = validateSkill(['name' => 'foo', 'description' => 'X.', 'bogus' => 'value']);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('UNKNOWN_TOP_LEVEL_KEY');
});

test('validate() accepts optional license, compatibility, metadata, allowed-tools', function (): void {
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'license'       => 'Apache-2.0',
        'compatibility' => 'Requires git',
        'metadata'      => ['author' => 'spora'],
        'allowed-tools' => 'calculator time',
    ]);

    expect($result->isValid())->toBeTrue();
});

test('validate() accepts a space-separated allowed-tools list', function (): void {
    $result = validateSkillResolving([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'calculator time',
    ]);

    expect($result->isValid())->toBeTrue()
        ->and($result->warnings())->toBe([]);
});

test('validate() rejects a comma-separated allowed-tools list and names the entry', function (): void {
    // The shipped `spora-plugin-minimax/skills/minimax-image-to-video` writes
    // this form, and the field was unchecked while it did. The spec separator is
    // a space, so the entry is malformed rather than silently accepted.
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'Bash(git:*), Read',
    ]);

    expect($result->isValid())->toBeFalse()
        ->and(array_column($result->errors(), 'code'))->toBe([
            'ALLOWED_TOOLS_INVALID',
            'ALLOWED_TOOLS_INVALID',
        ])
        ->and(messagesFor('ALLOWED_TOOLS_INVALID', $result->errors()))
        ->toContain('Bash(git:*),')
        ->toContain('Read');
});

test('validate() rejects a fully-qualified class name in allowed-tools', function (): void {
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'Spora\Tools\MediaTool',
    ]);

    expect($result->isValid())->toBeFalse()
        ->and(array_column($result->errors(), 'severity'))->toContain('error')
        ->and(messagesFor('ALLOWED_TOOLS_INVALID', $result->errors()))->toContain('Spora\Tools\MediaTool');
});

test('validate() reports one ALLOWED_TOOLS_INVALID per malformed entry', function (): void {
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'Bash(git:*) A, 1tool time',
    ]);

    expect(array_column($result->errors(), 'code'))->toBe([
        'ALLOWED_TOOLS_INVALID',
        'ALLOWED_TOOLS_INVALID',
        'ALLOWED_TOOLS_INVALID',
    ])
        ->and(messagesFor('ALLOWED_TOOLS_INVALID', $result->errors()))->toContain('Bash(git:*)')
        ->toContain('A,')
        ->toContain('1tool');
});

test('validate() describes the grammar and rejects FQCNs when allowed-tools is not a string', function (): void {
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => ['calculator', 'time'],
    ]);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('ALLOWED_TOOLS_INVALID');

    $message = messagesFor('ALLOWED_TOOLS_INVALID', $result->errors());
    // The old message promised "spaces or commas" while the code split on
    // neither, and the shipped skills that read it wrote FQCNs.
    expect($message)->toContain('space-separated')
        ->and($message)->not->toContain('spaces or commas')
        ->and($message)->toContain('fully-qualified class names are not accepted');
});

test('validate() rejects metadata with non-string values', function (): void {
    $result = validateSkill([
        'name'        => 'foo',
        'description' => 'X.',
        'metadata'    => ['version' => 2],
    ]);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('METADATA_VALUE_INVALID');
});

test('validate() rejects compatibility over 500 chars', function (): void {
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'compatibility' => str_repeat('a', 600),
    ]);

    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('COMPATIBILITY_TOO_LONG');
});

test('validate() emits SKILL_BODY_OVERSIZE warning for body over 500 lines', function (): void {
    $body = str_repeat("line\n", 600);
    $result = validateSkill(['name' => 'foo', 'description' => 'X.'], $body);

    expect($result->isValid())->toBeTrue();
    expect(array_column($result->warnings(), 'code'))->toContain('SKILL_BODY_OVERSIZE');
});

test('validate() emits SKILL_BODY_OVERSIZE warning for body over 50000 bytes', function (): void {
    $body = str_repeat('a', 60_000);
    $result = validateSkill(['name' => 'foo', 'description' => 'X.'], $body);

    expect($result->isValid())->toBeTrue();
    expect(array_column($result->warnings(), 'code'))->toContain('SKILL_BODY_OVERSIZE');
});

test('validate() warns, not errors, for a legal name no installed tool answers to', function (): void {
    // A skill may legitimately name a tool the deployment it was installed into
    // does not have. Rejecting that would make the field un-servable on a
    // partial install.
    $result = validateSkillResolving([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'typo_tool',
    ]);

    expect($result->isValid())->toBeTrue()
        ->and($result->errors())->toBe([])
        ->and(array_column($result->warnings(), 'code'))->toBe(['ALLOWED_TOOLS_UNKNOWN_TOOL'])
        ->and(array_column($result->warnings(), 'severity'))->toBe(['warning'])
        ->and(messagesFor('ALLOWED_TOOLS_UNKNOWN_TOOL', $result->warnings()))->toContain('typo_tool');
});

test('validate() names every unresolvable entry, leaving resolvable ones alone', function (): void {
    $result = validateSkillResolving([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'calculator typo_tool another_typo',
    ]);

    expect(array_column($result->warnings(), 'code'))->toBe([
        'ALLOWED_TOOLS_UNKNOWN_TOOL',
        'ALLOWED_TOOLS_UNKNOWN_TOOL',
    ])
        ->and(messagesFor('ALLOWED_TOOLS_UNKNOWN_TOOL', $result->warnings()))->toContain('another_typo');
});

test('validate() resolves nothing when no resolver was injected', function (): void {
    // The validator is also constructed bare, over frontmatter alone, by unit
    // tests and build-time checks. Skipping resolution must leave the syntax
    // checks working and produce no finding.
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'typo_tool',
    ]);

    expect($result->isValid())->toBeTrue()
        ->and($result->warnings())->toBe([])
        ->and($result->errors())->toBe([]);
});

test('validate() keeps the syntax check working without a resolver', function (): void {
    $result = validateSkill([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'Spora\Tools\MediaTool',
    ]);

    expect($result->isValid())->toBeFalse()
        ->and(array_column($result->errors(), 'code'))->toContain('ALLOWED_TOOLS_INVALID');
});

test('validate() emits no resolution finding for a list that failed to parse', function (): void {
    // Warning about a name that is not a name would be noise on top of the
    // error, and the entry is dropped from the parsed list anyway.
    $result = validateSkillResolving([
        'name'          => 'foo',
        'description'   => 'X.',
        'allowed-tools' => 'typo_tool Spora\Tools\MediaTool',
    ]);

    expect($result->isValid())->toBeFalse()
        ->and(array_column($result->errors(), 'code'))->toBe(['ALLOWED_TOOLS_INVALID'])
        ->and($result->warnings())->toBe([]);
});
