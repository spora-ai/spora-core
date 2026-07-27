<?php

declare(strict_types=1);

use Spora\Drivers\Utilities\ContentBlockParserRegistry;
use Spora\Drivers\Utilities\LLMContentParser;
use Spora\Drivers\Utilities\RedactedThinkingBlockParser;
use Spora\Drivers\Utilities\TextBlockParser;
use Spora\Drivers\Utilities\ThinkingBlockParser;
use Spora\Drivers\Utilities\ThinkingTagExtractor;
use Spora\Drivers\ValueObjects\ContentBlock;

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

test('parse returns the empty shape for null input', function (): void {
    $result = LLMContentParser::parse(null);

    expect($result)->toBe(['contentBlocks' => [], 'textContent' => '']);
});

test('parse returns the empty shape for an empty block array', function (): void {
    $result = LLMContentParser::parse([]);

    expect($result)->toBe(['contentBlocks' => [], 'textContent' => '']);
});

test('parse returns the input string as textContent for a plain string with no thinking tags', function (): void {
    $result = LLMContentParser::parse('Just a normal answer.');

    expect($result['textContent'])->toBe('Just a normal answer.')
        ->and($result['contentBlocks'])->toHaveCount(1)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('parse dispatches text blocks through TextBlockParser', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'text', 'text' => 'Hello.'],
    ]);

    expect($result['textContent'])->toBe('Hello.')
        ->and($result['contentBlocks'])->toHaveCount(1)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('parse concatenates textContent across multiple text blocks', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'text', 'text' => 'First.'],
        ['type' => 'text', 'text' => 'Second.'],
    ]);

    expect($result['textContent'])->toBe('First.Second.')
        ->and($result['contentBlocks'])->toHaveCount(2);
});

test('parse preserves thinking blocks in contentBlocks while only emitting text blocks into textContent', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'thinking', 'thinking' => 'Plan A.'],
        ['type' => 'thinking', 'thinking' => 'Plan B.'],
        ['type' => 'text', 'text' => 'Done.'],
    ]);

    expect($result['textContent'])->toBe('Done.')
        ->and($result['contentBlocks'])->toHaveCount(3)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($result['contentBlocks'][0]->text)->toBe('Plan A.')
        ->and($result['contentBlocks'][1]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($result['contentBlocks'][1]->text)->toBe('Plan B.')
        ->and($result['contentBlocks'][2]->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($result['contentBlocks'][2]->text)->toBe('Done.');
});

test('parse dispatches redacted_thinking blocks through RedactedThinkingBlockParser', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'redacted_thinking', 'data' => 'opaque-blob'],
        ['type' => 'text', 'text' => 'Visible.'],
    ]);

    expect($result['textContent'])->toBe('Visible.')
        ->and($result['contentBlocks'])->toHaveCount(2)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING)
        ->and($result['contentBlocks'][1]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('parse combines thinking, redacted_thinking, and text blocks in any order', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'thinking',          'thinking' => 'reasoned thought'],
        ['type' => 'redacted_thinking', 'data'     => 'opaque'],
        ['type' => 'text',              'text'     => 'final answer'],
    ]);

    expect($result['textContent'])->toBe('final answer')
        ->and($result['contentBlocks'])->toHaveCount(3)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($result['contentBlocks'][1]->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING)
        ->and($result['contentBlocks'][2]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('parse silently skips unknown block types while preserving tool_use blocks', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'image',      'source' => ['type' => 'base64', 'data' => '...']],
        ['type' => 'tool_use',   'id'     => 'abc', 'name' => 'search', 'input' => []],
        ['type' => 'text',       'text'   => 'only-text'],
        ['type' => 'unsupported','x'      => 1],
    ]);

    expect($result['textContent'])->toBe('only-text')
        ->and($result['contentBlocks'])->toHaveCount(2)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_TOOL_USE)
        ->and($result['contentBlocks'][1]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('parse silently skips non-array entries inside a block array', function (): void {
    $result = LLMContentParser::parse([
        'not-an-array',
        null,
        42,
        ['type' => 'text', 'text' => 'survived.'],
    ]);

    expect($result['textContent'])->toBe('survived.')
        ->and($result['contentBlocks'])->toHaveCount(1);
});

test('parse handles a thinking block with no thinking key without crashing', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'thinking'], // missing 'thinking' key
        ['type' => 'text', 'text' => 'answer'],
    ]);

    expect($result['textContent'])->toBe('answer')
        ->and($result['contentBlocks'])->toHaveCount(2)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($result['contentBlocks'][0]->text)->toBe('');
});

test('parse passes a plain text block through with no tag extraction', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'text', 'text' => 'no tags here'],
    ]);

    expect($result['textContent'])->toBe('no tags here')
        ->and($result['contentBlocks'])->toHaveCount(1)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('parse strips inline <think> tags from a plain-string input and exposes only the cleaned text', function (): void {
    $result = LLMContentParser::parse('<think>plan</think>The answer is 42.');

    expect($result['textContent'])->toBe('The answer is 42.')
        ->and($result['contentBlocks'])->toHaveCount(1)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_TEXT);
});

// Pins the structured shape the OpenAI driver emits from
// `resolveMessageBlocks()`: leading unsigned `thinking` block followed by
// a `text` block, with the cleaned text (no inline tags) in textContent.
test('parse preserves the leading thinking + text ordering the OpenAI driver emits', function (): void {
    $result = LLMContentParser::parse([
        ['type' => 'thinking', 'thinking' => 'plan a, then b', 'signature' => ''],
        ['type' => 'text',     'text'     => 'final answer'],
    ]);

    expect($result['textContent'])->toBe('final answer')
        ->and($result['contentBlocks'])->toHaveCount(2)
        ->and($result['contentBlocks'][0]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($result['contentBlocks'][0]->text)->toBe('plan a, then b')
        ->and($result['contentBlocks'][0]->signature)->toBe('')
        ->and($result['contentBlocks'][1]->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($result['contentBlocks'][1]->text)->toBe('final answer');
});

// ---------------------------------------------------------------------------
// TextBlockParser
// ---------------------------------------------------------------------------

test('TextBlockParser returns the block text as content with no displayReasoning', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse(['type' => 'text', 'text' => 'hello world']);

    expect($parsed->textContent)->toBe('hello world')
        ->and($parsed->contentBlock)->toBeInstanceOf(ContentBlock::class)
        ->and($parsed->contentBlock->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($parsed->contentBlock->text)->toBe('hello world');
});

test('TextBlockParser falls back to empty content when the text key is missing', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse(['type' => 'text']);

    expect($parsed->textContent)->toBe('')
        ->and($parsed->contentBlock)->toBeNull();
});

test('TextBlockParser strips embedded thinking tags from its text payload', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse([
        'type' => 'text',
        'text' => 'visible answer <thinking>inner reasoning</thinking>',
    ]);

    expect($parsed->textContent)->toBe('visible answer')
        ->and($parsed->contentBlock)->not->toBeNull()
        ->and($parsed->contentBlock->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($parsed->contentBlock->text)->toBe('visible answer');
});

test('TextBlockParser returns the cleaned text when the embedded thinking block is empty', function (): void {
    $parser = new TextBlockParser();

    $parsed = $parser->parse([
        'type' => 'text',
        'text' => 'answer <thinking>   </thinking>',
    ]);

    expect($parsed->textContent)->toBe('answer')
        ->and($parsed->contentBlock->text)->toBe('answer');
});

// ---------------------------------------------------------------------------
// ThinkingBlockParser
// ---------------------------------------------------------------------------

test('ThinkingBlockParser preserves the thinking string as a signed content block', function (): void {
    $parser = new ThinkingBlockParser();

    $parsed = $parser->parse([
        'type'      => 'thinking',
        'thinking'  => 'chain of thought',
        'signature' => 'sig-1',
    ]);

    expect($parsed->textContent)->toBe('')
        ->and($parsed->contentBlock)->toBeInstanceOf(ContentBlock::class)
        ->and($parsed->contentBlock->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($parsed->contentBlock->text)->toBe('chain of thought')
        ->and($parsed->contentBlock->signature)->toBe('sig-1');
});

test('ThinkingBlockParser returns a thinking content block with empty text when the thinking key is missing', function (): void {
    $parser = new ThinkingBlockParser();

    $parsed = $parser->parse(['type' => 'thinking']);

    expect($parsed->textContent)->toBe('')
        ->and($parsed->contentBlock)->toBeInstanceOf(ContentBlock::class)
        ->and($parsed->contentBlock->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($parsed->contentBlock->text)->toBe('');
});

test('ThinkingBlockParser coerces a non-string thinking value to string', function (): void {
    $parser = new ThinkingBlockParser();

    $parsed = $parser->parse(['type' => 'thinking', 'thinking' => 42]);

    expect($parsed->contentBlock->text)->toBe('42');
});

// ---------------------------------------------------------------------------
// RedactedThinkingBlockParser
// ---------------------------------------------------------------------------

test('RedactedThinkingBlockParser returns a redacted_thinking content block with the opaque data payload', function (): void {
    $parser = new RedactedThinkingBlockParser();

    $parsed = $parser->parse(['type' => 'redacted_thinking', 'data' => 'opaque-blob']);

    expect($parsed->textContent)->toBe('')
        ->and($parsed->contentBlock)->toBeInstanceOf(ContentBlock::class)
        ->and($parsed->contentBlock->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING)
        ->and($parsed->contentBlock->data)->toBe('opaque-blob');
});

test('RedactedThinkingBlockParser ignores extra keys and still returns the redacted block', function (): void {
    $parser = new RedactedThinkingBlockParser();

    $parsed = $parser->parse([
        'type'             => 'redacted_thinking',
        'data'             => 'whatever',
        'unexpected_field' => ['nested' => true],
    ]);

    expect($parsed->contentBlock->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING)
        ->and($parsed->contentBlock->data)->toBe('whatever');
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
    $result = ThinkingTagExtractor::strip('Just plain text.');

    expect($result)->toBe('Just plain text.');
});

test('ThinkingTagExtractor strips <think>...</think> tags and drops the body', function (): void {
    $result = ThinkingTagExtractor::strip('<think>plan</think>The answer is 42.');

    expect($result)->toBe('The answer is 42.');
});

test('ThinkingTagExtractor strips <thinking>...</thinking> tags and drops the body', function (): void {
    $result = ThinkingTagExtractor::strip('<thinking>thought</thinking>answer');

    expect($result)->toBe('answer');
});

test('ThinkingTagExtractor strips <thought>...</thought> tags and drops the body', function (): void {
    $result = ThinkingTagExtractor::strip('<thought>idea</thought>answer');

    expect($result)->toBe('answer');
});

test('ThinkingTagExtractor strips multiple tags in sequence and collapses adjacent whitespace', function (): void {
    // Two adjacent tags produce a double space which the strip helper
    // collapses to a single space (horizontal whitespace only).
    $result = ThinkingTagExtractor::strip('one. <thinking>step 1</thinking>  two. <thinking>step 2</thinking>');

    expect($result)->toBe('one. two.');
});

test('ThinkingTagExtractor preserves newlines in content while collapsing horizontal whitespace', function (): void {
    $result = ThinkingTagExtractor::strip("<thinking>plan</thinking>\n\n## Header\n\nParagraph");

    expect($result)->toBe("## Header\n\nParagraph");
});
