<?php

declare(strict_types=1);

use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\ValueObjects\ContentBlock;

test('parse returns contentBlocks with a text block and matching textContent when input is a plain text block', function (): void {
    $raw = [
        ['type' => 'text', 'text' => 'Plain text response.'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('Plain text response.')
        ->and($response['contentBlocks'])->toHaveCount(1)
        ->and($response['contentBlocks'][0])->toBeInstanceOf(ContentBlock::class)
        ->and($response['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($response['contentBlocks'][0]->text)->toBe('Plain text response.');
});

test('parse preserves signed thinking blocks in contentBlocks when content is an array of blocks', function (): void {
    $raw = [
        ['type' => 'thinking', 'thinking' => 'The user wants brownies. I should search for a recipe.'],
        ['type' => 'text', 'text' => 'Here is a vegan brownie recipe...'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('Here is a vegan brownie recipe...')
        ->and($response['contentBlocks'])->toHaveCount(2)
        ->and($response['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($response['contentBlocks'][0]->text)->toBe('The user wants brownies. I should search for a recipe.')
        ->and($response['contentBlocks'][1]->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($response['contentBlocks'][1]->text)->toBe('Here is a vegan brownie recipe...');
});

test('parse preserves redacted_thinking blocks in contentBlocks without surfacing the opaque payload', function (): void {
    $raw = [
        ['type' => 'redacted_thinking', 'data' => '...'],
        ['type' => 'text', 'text' => 'It works!'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('It works!')
        ->and($response['contentBlocks'])->toHaveCount(2)
        ->and($response['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING)
        ->and($response['contentBlocks'][1]->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($response['contentBlocks'][1]->text)->toBe('It works!');
});

test('parse returns the input string as textContent when content is a flat string', function (): void {
    $raw = 'Plain text response.';

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('Plain text response.')
        ->and($response['contentBlocks'])->toHaveCount(1)
        ->and($response['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('parse handles content=null gracefully in legacy response', function (): void {
    $response = LLMContentParser::parse(null);

    expect($response)->toBe(['contentBlocks' => [], 'textContent' => '']);
});

test('parse handles empty array gracefully', function (): void {
    $response = LLMContentParser::parse([]);

    expect($response)->toBe(['contentBlocks' => [], 'textContent' => '']);
});

test('parse strips XML <thinking> tags from plain string content', function (): void {
    $raw = 'You should tip $6.30. <thinking>I need to calculate 15% tip for $42. 15% of 42 is 6.3.</thinking>';

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('You should tip $6.30.');
});

test('parse strips multiple XML <thinking> blocks and collapses horizontal whitespace between them', function (): void {
    $raw = 'Result one. <thinking>First step...</thinking>  Result two. <thinking>Second step...</thinking>';

    $response = LLMContentParser::parse($raw);

    // The two adjacent spaces around the first tag collapse to a single
    // space because ThinkingTagExtractor::strip() collapses [ \t]+.
    expect($response['textContent'])->toBe('Result one. Result two.');
});

test('parse strips XML <thinking> nested inside a text block', function (): void {
    $raw = [
        [
            'type' => 'text',
            'text' => 'Inner text. <thinking>Inner thinking within block</thinking>',
        ],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('Inner text.');
});

test('parse strips <thought> tags from plain string content', function (): void {
    $raw = 'The total is $42. <thought>I should calculate the total first.</thought>';

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('The total is $42.');
});

test('parse strips Anthropic-style thinking tags and preserves newlines in textContent', function (): void {
    $raw = "<think>Step one.\nStep two.\n</think>\n\n## Header\n\nList item 1\nList item 2";

    $response = LLMContentParser::parse($raw);

    // Newlines should be preserved, not collapsed to spaces
    expect($response['textContent'])->toBe("## Header\n\nList item 1\nList item 2");
});

test('parse preserves newlines in textContent when stripping thinking tags', function (): void {
    $raw = "<thinking>First thought</thinking>\n\n## Header\n\nParagraph here";

    $response = LLMContentParser::parse($raw);

    // Newlines should NOT be collapsed to spaces - only spaces/tabs collapsed
    expect($response['textContent'])->toBe("## Header\n\nParagraph here");
    expect($response['textContent'])->not->toContain('  '); // no double spaces
});

test('parse strips Anthropic thinking from response02.txt and preserves markdown formatting', function (): void {
    $raw = file_get_contents(__DIR__ . '/response02.txt');

    $response = LLMContentParser::parse($raw);

    // Content should NOT contain thinking tags
    expect($response['textContent'])->not->toContain('</think>');
    expect($response['textContent'])->not->toContain('<think>');

    // Content should start with markdown heading, not with thinking tag
    expect(trim($response['textContent']))->toStartWith('## Wetter in Deutschland');

    // Markdown formatting (tables) should be preserved with newlines
    expect($response['textContent'])->toContain('| Stadt |');
    expect($response['textContent'])->toContain('|-------|');
    expect($response['textContent'])->toContain('| **Köln**');
});

test('parse strips Anthropic thinking from response01.txt and preserves the answer', function (): void {
    $raw = file_get_contents(__DIR__ . '/response01.txt');

    $response = LLMContentParser::parse($raw);

    // Content should NOT contain thinking tags
    expect($response['textContent'])->not->toContain('</think>');
    expect($response['textContent'])->not->toContain('<think>');

    // Content should be the simple answer
    expect(trim($response['textContent']))->toBe('6 × 7 = **42**');
});

test('parse skips non-array blocks mixed into a block array', function (): void {
    $raw = [
        'not-an-array', // scalar — should be ignored, not crash
        ['type' => 'text', 'text' => 'Hello.'],
        null, // also non-array
        ['type' => 'text', 'text' => ' World.'],
    ];

    $response = LLMContentParser::parse($raw);

    // Each text block is trimmed individually; the leading space on
    // " World." is dropped, so the two blocks concatenate to
    // "Hello.World." (no space).
    expect($response['textContent'])->toBe('Hello.World.')
        ->and($response['contentBlocks'])->toHaveCount(2);
});

test('parse falls back to empty text when a text block has no text key', function (): void {
    $raw = [
        ['type' => 'text'], // missing 'text' key
        ['type' => 'text', 'text' => 'after'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('after');
});

test('parse handles thinking blocks with no thinking key without crashing', function (): void {
    $raw = [
        ['type' => 'thinking'], // missing 'thinking' key
        ['type' => 'text', 'text' => 'answer'],
    ];

    $response = LLMContentParser::parse($raw);

    expect($response['textContent'])->toBe('answer')
        ->and($response['contentBlocks'])->toHaveCount(2)
        ->and($response['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($response['contentBlocks'][0]->text)->toBe('');
});
