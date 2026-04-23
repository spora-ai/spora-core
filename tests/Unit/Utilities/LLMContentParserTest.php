<?php

declare(strict_types=1);

use Spora\Drivers\Utilities\LLMContentParser;

test('parse returns reasoning=null and content when array is plain text block', function (): void {
    $raw = [
        ['type' => 'text', 'text' => 'Plain text response.'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('Plain text response.')
        ->and($response['reasoning'])->toBeNull();
});

test('parse extracts thinking block as reasoning when content is an array of blocks', function (): void {
    $raw = [
        ['type' => 'thinking', 'thinking' => 'The user wants brownies. I should search for a recipe.'],
        ['type' => 'text', 'text' => 'Here is a vegan brownie recipe...'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('Here is a vegan brownie recipe...')
        ->and($response['reasoning'])->toBe('The user wants brownies. I should search for a recipe.');
});

test('parse handles redacted_thinking from Anthropic', function (): void {
    $raw = [
        ['type' => 'redacted_thinking', 'data' => '...'],
        ['type' => 'text', 'text' => 'It works!'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('It works!')
        ->and($response['reasoning'])->toBe('[Redacted Thinking]');
});

test('parse returns reasoning=null when content is a flat string', function (): void {
    $raw = 'Plain text response.';

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('Plain text response.')
        ->and($response['reasoning'])->toBeNull();
});

test('parse handles content=null gracefully in legacy response', function (): void {
    $response = LLMContentParser::parse(null);

    expect($response['content'])->toBe('')
        ->and($response['reasoning'])->toBeNull();
});

test('parse handles empty array gracefully', function (): void {
    $response = LLMContentParser::parse([]);

    expect($response['content'])->toBe('')
        ->and($response['reasoning'])->toBeNull();
});

test('parse extracts XML <thinking> tags as reasoning from plain string content', function (): void {
    $raw = '<thinking>I need to calculate 15% tip for $42. 15% of 42 is 6.3.</thinking><text>You should tip $6.30.</text>';

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('You should tip $6.30.')
        ->and($response['reasoning'])->toBe('I need to calculate 15% tip for $42. 15% of 42 is 6.3.');
});

test('parse extracts multiple XML <thinking> blocks and concatenates them', function (): void {
    $raw = '<thinking>First step...</thinking><text>Result one.</text><thinking>Second step...</thinking><text>Result two.</text>';

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('Result one. Result two.')
        ->and($response['reasoning'])->toBe("First step...\nSecond step...");
});

test('parse extracts XML <thinking> nested inside a text block', function (): void {
    $raw = [
        [
            'type' => 'text',
            'text' => '<thinking>Inner thinking within block</thinking>Inner text.',
        ],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('Inner text.')
        ->and($response['reasoning'])->toBe('Inner thinking within block');
});

test('parse extracts <thought> tags as reasoning from plain string content', function (): void {
    $raw = '<thought>I should calculate the total first.</thought><text>The total is $42.</text>';

    $response = LLMContentParser::parse($raw);

    expect($response['content'])->toBe('The total is $42.')
        ->and($response['reasoning'])->toBe('I should calculate the total first.');
});

test('parse extracts Anthropic-style thinking tags and preserves newlines in content', function (): void {
    $raw = "<think>Step one.\nStep two.\n</think>\n\n## Header\n\nList item 1\nList item 2";

    $response = LLMContentParser::parse($raw);

    expect($response['reasoning'])->toBe("Step one.\nStep two.");
    // Newlines should be preserved, not collapsed to spaces
    expect($response['content'])->toBe("## Header\n\nList item 1\nList item 2");
});

test('parse preserves newlines in content when stripping thinking tags', function (): void {
    $raw = "<thinking>First thought</thinking>\n\n## Header\n\nParagraph here";

    $response = LLMContentParser::parse($raw);

    // Newlines should NOT be collapsed to spaces - only spaces/tabs collapsed
    expect($response['content'])->toBe("## Header\n\nParagraph here");
    expect($response['content'])->not()->toContain('  '); // no double spaces
});

test('parse extracts Anthropic thinking from response02.txt and preserves markdown formatting', function (): void {
    $raw = file_get_contents(__DIR__ . '/response02.txt');

    $response = LLMContentParser::parse($raw);

    // Thinking should be extracted into reasoning field
    expect($response['reasoning'])->not()->toBeNull();
    expect($response['reasoning'])->toContain('Wie wird das Wetter');

    // Content should NOT contain thinking tags
    expect($response['content'])->not()->toContain('</think>');
    expect($response['content'])->not()->toContain('<think>');

    // Content should start with markdown heading, not with thinking tag
    expect(trim($response['content']))->toStartWith('## Wetter in Deutschland');

    // Markdown formatting (tables) should be preserved with newlines
    expect($response['content'])->toContain("| Stadt |");
    expect($response['content'])->toContain("|-------|");
    expect($response['content'])->toContain("| **Köln**");
});

test('parse extracts Anthropic thinking from response01.txt and preserves content', function (): void {
    $raw = file_get_contents(__DIR__ . '/response01.txt');

    $response = LLMContentParser::parse($raw);

    // Thinking should be extracted
    expect($response['reasoning'])->not()->toBeNull();
    expect($response['reasoning'])->toContain('6x7');

    // Content should NOT contain thinking tags
    expect($response['content'])->not()->toContain('</think>');
    expect($response['content'])->not()->toContain('<think>');

    // Content should be the simple answer
    expect(trim($response['content']))->toBe("6 × 7 = **42**");
});
