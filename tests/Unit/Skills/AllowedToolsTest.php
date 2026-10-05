<?php

declare(strict_types=1);

use Spora\Skills\AllowedTools;
use Spora\Tools\Attributes\Tool;

test('names() splits on any run of whitespace, not a single space', function (): void {
    // A YAML folded or multi-line scalar arrives with embedded newlines, and a
    // newline is not a tool name — so the split cannot be a literal space.
    expect(AllowedTools::names('agent read_url'))->toBe(['agent', 'read_url'])
        ->and(AllowedTools::names("agent\nread_url"))->toBe(['agent', 'read_url'])
        ->and(AllowedTools::names("agent \t read_url\n"))->toBe(['agent', 'read_url']);
});

test('names() dedupes in first-seen order', function (): void {
    expect(AllowedTools::names('read_url agent read_url time read_url'))->toBe(['read_url', 'agent', 'time']);
});

test('names() caps the list at MAX_ENTRIES, keeping the first ones', function (): void {
    $names = array_map(static fn(int $i): string => "tool_{$i}", range(1, 40));
    $parsed = AllowedTools::names(implode(' ', $names));

    expect(AllowedTools::MAX_ENTRIES)->toBe(32)
        ->and($parsed)->toHaveCount(32)
        ->and($parsed[0])->toBe('tool_1')
        ->and($parsed[31])->toBe('tool_32')
        // The 33rd legal name is dropped, so a raised cap is what makes this fail.
        ->and($parsed)->not->toContain('tool_33');
});

test('names() keeps a bare tool name and drops a fully-qualified class name', function (): void {
    // What the shipped plugin skills write: an FQCN, never a wire name.
    expect(AllowedTools::names('Spora\Tools\MediaTool'))->toBe([])
        ->and(AllowedTools::names('read_url Spora\Tools\MediaTool'))->toBe(['read_url']);
});

test('names() drops a comma-suffixed entry rather than splitting on commas', function (): void {
    // The spec separator is a space. Treating a comma as one too would ship two
    // grammars for a field compared against #[Tool(name:)] values.
    expect(AllowedTools::names('a, b'))->toBe(['b'])
        ->and(AllowedTools::names('read_url,agent'))->toBe([]);
});

test('names() drops the parenthesised scoped form', function (): void {
    expect(AllowedTools::names('Bash(git:*) read_url'))->toBe(['read_url']);
});

test('names() returns an empty list for an empty or whitespace-only value', function (): void {
    expect(AllowedTools::names(''))->toBe([])
        ->and(AllowedTools::names("  \n\t "))->toBe([]);
});

test('entries() keeps the entries as written so a finding can name them', function (): void {
    // The validator reports the offending entry; a filtered list could not.
    expect(AllowedTools::entries('Spora\Tools\MediaTool,  agent'))->toBe(['Spora\Tools\MediaTool,', 'agent'])
        ->and(AllowedTools::entries("\tagent\n\nread_url\n"))->toBe(['agent', 'read_url']);
});

test('the entry rule is the one the #[Tool] attribute enforces', function (): void {
    // Not a copy of the pattern — the shared constant, so a skill cannot declare
    // a name the attribute would refuse.
    foreach (['agent', 'read_url', 'tavily_search', 'a_b2'] as $name) {
        expect(AllowedTools::names($name))->toBe([$name])
            ->and(preg_match(Tool::NAME_REGEX, $name))->toBe(1);
    }

    foreach (['Agent', '9lives', 'read-url', ''] as $name) {
        expect(AllowedTools::names($name))->toBe([])
            ->and(preg_match(Tool::NAME_REGEX, $name))->toBe(0);
    }
});
