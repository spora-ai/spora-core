<?php

declare(strict_types=1);

use Spora\Skills\SkillValidator;

function validateSkill(array $frontmatter, ?string $body = null, ?string $parentDirName = null): Spora\Skills\ValidationResult
{
    return (new SkillValidator())->validate($frontmatter, $body, $parentDirName);
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
        'allowed-tools' => 'Bash(git:*) Read',
    ]);

    expect($result->isValid())->toBeTrue();
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
