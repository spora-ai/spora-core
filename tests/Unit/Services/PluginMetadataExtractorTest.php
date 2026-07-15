<?php

declare(strict_types=1);

use Spora\Services\PluginMetadataExtractor;
use Spora\Tools\ReadUrlTool;

test('extract() reads the #[Tool] attribute from a class via reflection (no instantiation)', function (): void {
    $extractor = new PluginMetadataExtractor();

    $result = $extractor->extract([ReadUrlTool::class]);

    expect($result)->toBe([
        [
            'name'        => 'read_url',
            'description' => 'Fetch and read the contents of a URL. Parses HTML pages into Markdown, can read XML/RSS/JSON, and can fetch remote PDFs and convert them to Markdown. Only http:// and https:// URLs are supported.',
        ],
    ]);
});

test('extract() returns an empty array when given no tool classes', function (): void {
    $extractor = new PluginMetadataExtractor();

    expect($extractor->extract([]))->toBe([]);
});

test('extract() silently skips classes that lack a #[Tool] attribute', function (): void {
    $extractor = new PluginMetadataExtractor();

    // \stdClass is universally available and definitely has no #[Tool] attribute.
    $result = $extractor->extract([stdClass::class]);

    expect($result)->toBe([]);
});
