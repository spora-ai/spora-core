<?php

declare(strict_types=1);

use Spora\Drivers\Utilities\ContentBlockParserRegistry;
use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\Utilities\RedactedThinkingBlockParser;
use Spora\Drivers\Utilities\TextBlockParser;
use Spora\Drivers\Utilities\ThinkingBlockParser;
use Spora\Drivers\Utilities\ThinkingTagExtractor;

/*
|--------------------------------------------------------------------------
| LLMContentParser (refactored: per-block-type parsers)
|--------------------------------------------------------------------------
|
| These tests cover the new structure introduced in the
| `refactor/llm-content-parser-complexity` PR:
|   - the thin top-level dispatcher in LLMContentParser::parse()
|   - the per-block-type parsers (TextBlockParser, ThinkingBlockParser,
|     RedactedThinkingBlockParser)
|   - the registry that maps `type` strings to parsers
|   - the shared ThinkingTagExtractor helper
|
| The legacy / integration coverage for the dispatcher remains in
| tests/Unit/Utilities/LLMContentParserTest.php.
*/

// ---------------------------------------------------------------------------
// Top-level dispatcher (LLMContentParser::parse)
// ---------------------------------------------------------------------------

test('parse returns empty content and null reasoning for null input', function (): void {
    $result = LLMContentParser::parse(null);

    expect($result)->toBe(['content' => '', 'reasoning' => null]);
});

test('parse returns empty content and null reasoning for an empty block array', function (): void {
    $result = LLMContentParser::parse([]);

    expect($result)->toBe(['content' => '', 'reasoning' => null]);
});

test('parse returns empty content and null reasoning for a plain string with no thinking tags', function (): void {
    $result = LLMContentParser::parse('Just a normal answer.');

    expect($result['content'])->toBe('Just a normal answer.')
        ->and($result['reasoning'])->toBeNull();
});

test('parse dispatches text blocks through TextBlockParser', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'text', 'text' => 'Hello.'],
    ]);

    expect($result['content'])->toBe('Hello.')
        ->and($result['reasoning'])->toBeNull();
});

test('parse concatenates content and reasoning across multiple text blocks', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'text', 'text' => 'First.'],
        ['type' => 'text', 'text' => ' Second.'],
    ]);

    expect($result['content'])->toBe('First. Second.')
        ->and($result['reasoning'])->toBeNull();
});

test('parse dispatches thinking blocks through ThinkingBlockParser and joins with newline', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'thinking', 'thinking' => 'Plan A.'],
        ['type' => 'thinking', 'thinking' => 'Plan B.'],
        ['type' => 'text', 'text' => 'Done.'],
    ]);

    expect($result['content'])->toBe('Done.')
        ->and($result['reasoning'])->toBe("Plan A.\nPlan B.");
});

test('parse dispatches redacted_thinking blocks through RedactedThinkingBlockParser', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'redacted_thinking', 'data' => 'opaque-blob'],
        ['type' => 'text', 'text' => 'Visible.'],
    ]);

    expect($result['content'])->toBe('Visible.')
        ->and($result['reasoning'])->toBe('[Redacted Thinking]');
});

test('parse combines thinking, redacted_thinking, and text blocks in any order', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'thinking',          'thinking' => 'reasoned thought'],
        ['type' => 'redacted_thinking', 'data'     => 'opaque'],
        ['type' => 'text',              'text'     => 'final answer'],
    ]);

    expect($result['content'])->toBe('final answer')
        ->and($result['reasoning'])->toBe("reasoned thought\n[Redacted Thinking]");
});

test('parse silently skips unknown block types', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'image',      'source' => ['type' => 'base64', 'data' => '...']],
        ['type' => 'tool_use',   'id'     => 'abc', 'name' => 'search', 'input' => []],
        ['type' => 'text',       'text'   => 'only-text'],
        ['type' => 'unsupported','x'      => 1],
    ]);

    expect($result['content'])->toBe('only-text')
        ->and($result['reasoning'])->toBeNull();
});

test('parse silently skips non-array entries inside a block array', function (): void {
    $result = LLMContentParser::parse([
        'not-an-array',
        null,
        42,
        ['type' => 'text', 'text' => 'survived.'],
    ]);

    expect($result['content'])->toBe('survived.')
        ->and($result['reasoning'])->toBeNull();
});

test('parse keeps an empty-string reasoning when a thinking block has no thinking key and nothing else supplies reasoning', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'thinking'], // missing 'thinking' key
        ['type' => 'text', 'text' => 'answer'],
    ]);

    expect($result['content'])->toBe('answer')
        ->and($result['reasoning'])->toBe('');
});

test('parse returns null reasoning for a text block with no embedded thinking tags', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'text', 'text' => 'no tags here'],
    ]);

    expect($result['reasoning'])->toBeNull();
});

test('parse extracts inline <think> tags from a plain-string input', function (): void {
    $result = LLMContentParser::parse('<think>plan</think>The answer is 42.');

    expect($result['content'])->toBe('The answer is 42.')
        ->and($result['reasoning'])->toBe('plan');
});

// ---------------------------------------------------------------------------
// TextBlockParser
// ---------------------------------------------------------------------------

test('TextBlockParser returns the block text as content with no reasoning', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse(['type' => 'text', 'text' => 'hello world']);

    expect($parsed->content)->toBe('hello world')
        ->and($parsed->reasoning)->toBeNull();
});

test('TextBlockParser falls back to empty content when the text key is missing', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse(['type' => 'text']);

    expect($parsed->content)->toBe('')
        ->and($parsed->reasoning)->toBeNull();
});

test('TextBlockParser extracts embedded thinking tags from its text payload', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse([
        'type' => 'text',
        'text' => '<thinking>inner reasoning</thinking>visible answer',
    ]);

    expect($parsed->content)->toBe('visible answer')
        ->and($parsed->reasoning)->toBe('inner reasoning');
});

test('TextBlockParser returns null reasoning when the embedded thinking block is empty', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse([
        'type' => 'text',
        'text' => '<thinking>   </thinking>answer',
    ]);

    expect($parsed->content)->toBe('answer')
        ->and($parsed->reasoning)->toBeNull();
});

// ---------------------------------------------------------------------------
// ThinkingBlockParser
// ---------------------------------------------------------------------------

test('ThinkingBlockParser returns the thinking string as reasoning with empty content', function (): void {
    $parser = new ThinkingBlockParser();

    $parsed = $parser->parse([
        'type'     => 'thinking',
        'thinking' => 'chain of thought',
    ]);

    expect($parsed->content)->toBe('')
        ->and($parsed->reasoning)->toBe('chain of thought');
});

test('ThinkingBlockParser returns an empty-string reasoning when the thinking key is missing', function (): void {
    $parser = new ThinkingBlockParser();

    $parsed = $parser->parse(['type' => 'thinking']);

    expect($parsed->content)->toBe('')
        ->and($parsed->reasoning)->toBe('');
});

test('ThinkingBlockParser coerces a non-string thinking value to string', function (): void {
    $parser = new ThinkingBlockParser();

    $parsed = $parser->parse(['type' => 'thinking', 'thinking' => 42]);

    expect($parsed->reasoning)->toBe('42');
});

// ---------------------------------------------------------------------------
// RedactedThinkingBlockParser
// ---------------------------------------------------------------------------

test('RedactedThinkingBlockParser always returns the [Redacted Thinking] marker', function (): void {
    $parser = new RedactedThinkingBlockParser();

    $parsed = $parser->parse(['type' => 'redacted_thinking', 'data' => 'opaque-blob']);

    expect($parsed->content)->toBe('')
        ->and($parsed->reasoning)->toBe('[Redacted Thinking]');
});

test('RedactedThinkingBlockParser ignores extra keys and still returns the marker', function (): void {
    $parser = new RedactedThinkingBlockParser();

    $parsed = $parser->parse([
        'type'             => 'redacted_thinking',
        'data'             => 'whatever',
        'unexpected_field' => ['nested' => true],
    ]);

    expect($parsed->reasoning)->toBe('[Redacted Thinking]');
});

// ---------------------------------------------------------------------------
// ContentBlockParserRegistry
// ---------------------------------------------------------------------------

test('ContentBlockParserRegistry returns the correct parser for each known block type', function (): void {
    $registry = new ContentBlockParserRegistry();

    expect($registry->for('text'))->toBeInstanceOf(TextBlockParser::class)
        ->and($registry->for('thinking'))->toBeInstanceOf(ThinkingBlockParser::class)
        ->and($registry->for('redacted_thinking'))->toBeInstanceOf(RedactedThinkingBlockParser::class);
});

test('ContentBlockParserRegistry returns null for unknown block types', function (): void {
    $registry = new ContentBlockParserRegistry();

    expect($registry->for('image'))->toBeNull()
        ->and($registry->for('tool_use'))->toBeNull()
        ->and($registry->for(''))->toBeNull()
        ->and($registry->for('TOOL_RESULT'))->toBeNull(); // case-sensitive
});

// ---------------------------------------------------------------------------
// ThinkingTagExtractor
// ---------------------------------------------------------------------------

test('ThinkingTagExtractor returns the input unchanged when no thinking tags are present', function (): void {
    $result = ThinkingTagExtractor::extract('Just plain text.');

    expect($result['content'])->toBe('Just plain text.')
        ->and($result['reasoning'])->toBeNull();
});

test('ThinkingTagExtractor strips <think>...</think> tags and captures the body as reasoning', function (): void {
    $result = ThinkingTagExtractor::extract('<think>plan</think>The answer is 42.');

    expect($result['content'])->toBe('The answer is 42.')
        ->and($result['reasoning'])->toBe('plan');
});

test('ThinkingTagExtractor strips <thinking>...</thinking> tags and captures the body as reasoning', function (): void {
    $result = ThinkingTagExtractor::extract('<thinking>thought</thinking>answer');

    expect($result['content'])->toBe('answer')
        ->and($result['reasoning'])->toBe('thought');
});

test('ThinkingTagExtractor strips <thought>...</thought> tags and captures the body as reasoning', function (): void {
    $result = ThinkingTagExtractor::extract('<thought>idea</thought>answer');

    expect($result['content'])->toBe('answer')
        ->and($result['reasoning'])->toBe('idea');
});

test('ThinkingTagExtractor joins multiple reasoning captures with newlines', function (): void {
    $result = ThinkingTagExtractor::extract('<thinking>step 1</thinking><text>one.</text><thinking>step 2</thinking><text>two.</text>');

    expect($result['content'])->toBe('one. two.')
        ->and($result['reasoning'])->toBe("step 1\nstep 2");
});

test('ThinkingTagExtractor returns null reasoning when the captured thinking body is empty/whitespace', function (): void {
    $result = ThinkingTagExtractor::extract('<thinking>   </thinking>visible.');

    expect($result['content'])->toBe('visible.')
        ->and($result['reasoning'])->toBeNull();
});

test('ThinkingTagExtractor preserves newlines in content while collapsing horizontal whitespace', function (): void {
    $result = ThinkingTagExtractor::extract("<thinking>plan</thinking>\n\n## Header\n\nParagraph");

    expect($result['content'])->toBe("## Header\n\nParagraph");
});
