<?php

declare(strict_types=1);

use Spora\Drivers\Utilities\ThinkingTagExtractor;

/* Unit coverage for ThinkingTagExtractor::split(). The earlier strip()
   API is preserved and exercised indirectly through the parser tests. */

function tte_tag_open(): string
{
    return '<' . 'think' . '>';
}
function tte_tag_close(): string
{
    return '<' . '/' . 'think' . '>';
}
function tte_thinking_open(): string
{
    return '<' . 'thinking' . '>';
}
function tte_thinking_close(): string
{
    return '<' . '/' . 'thinking' . '>';
}
function tte_thought_open(): string
{
    return '<' . 'thought' . '>';
}
function tte_thought_close(): string
{
    return '<' . '/' . 'thought' . '>';
}

test('split returns the input unchanged when no reasoning tags are present', function (): void {
    $result = ThinkingTagExtractor::split('Just an answer.');

    expect($result)->toBe(['text' => 'Just an answer.', 'reasoning' => '']);
});

test('split extracts a single inline reasoning block and leaves the rest as text', function (): void {
    $result = ThinkingTagExtractor::split(
        'Visible answer. ' . tte_tag_open() . 'step 1: think.' . tte_tag_close() . ' trailing text.',
    );

    expect($result['text'])->toBe('Visible answer. trailing text.')
        ->and($result['reasoning'])->toBe('step 1: think.');
});

test('split joins multiple reasoning tags with a blank line', function (): void {
    $result = ThinkingTagExtractor::split(
        'prefix ' . tte_tag_open() . 'inner one' . tte_tag_close() . ' suffix ' . tte_tag_open() . 'inner two' . tte_tag_close() . ' end',
    );

    expect($result['text'])->toBe('prefix suffix end')
        ->and($result['reasoning'])->toBe("inner one\n\ninner two");
});

test('split ignores empty reasoning tags', function (): void {
    $result = ThinkingTagExtractor::split(
        'visible ' . tte_tag_open() . '   ' . tte_tag_close() . ' step ' . tte_tag_open() . '' . tte_tag_close() . ' end',
    );

    expect($result['text'])->toBe('visible step end')
        ->and($result['reasoning'])->toBe('');
});

test('split handles the alternative thinking and thought tag forms', function (): void {
    $result = ThinkingTagExtractor::split(
        'visible. ' . tte_thinking_open() . 'inner A.' . tte_thinking_close() . ' gap. ' . tte_thought_open() . 'inner B.' . tte_thought_close() . ' trailing.',
    );

    expect($result['text'])->toBe('visible. gap. trailing.')
        ->and($result['reasoning'])->toBe("inner A.\n\ninner B.");
});

test('split collapses runs of blank lines inside the extracted reasoning', function (): void {
    $result = ThinkingTagExtractor::split(
        'visible ' . tte_tag_open() . "step1\n\n\n\nstep2" . tte_tag_close() . ' end',
    );

    expect($result['text'])->toBe('visible end')
        ->and($result['reasoning'])->toBe("step1\n\nstep2");
});

test('split returns reasoning === "" when only whitespace lives inside the tags', function (): void {
    $result = ThinkingTagExtractor::split(
        'answer ' . tte_tag_open() . "\n\n\t  \n" . tte_tag_close() . ' tail',
    );

    expect($result['text'])->toBe('answer tail')
        ->and($result['reasoning'])->toBe('');
});

test('strip is a thin wrapper that returns just the text side of split()', function (): void {
    expect(ThinkingTagExtractor::strip('visible ' . tte_tag_open() . 'hidden' . tte_tag_close() . ' end'))
        ->toBe('visible end')
        ->and(ThinkingTagExtractor::strip('plain'))
        ->toBe('plain');
});
