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
