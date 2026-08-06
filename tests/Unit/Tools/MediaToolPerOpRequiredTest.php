<?php

declare(strict_types=1);

use Spora\Tools\MediaTool;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

/**
 * Per-op `required[]` narrowing for MediaTool's `asset_id` parameter.
 *
 * `asset_id` is bound to `get_media`, `get_public_url`, and `get_embed_code`
 * — the `search` op ignores it. The filter must drop `asset_id` from
 * `required[]` when only `search` is allowed (e.g. the agent enabled the
 * read-only search but not the per-asset lookups).
 *
 * The schema is built reflectively from a class-string — no MediaTool
 * instantiation, so no MediaArchiveService / AuthService wiring needed.
 */
it('declares `asset_id` as required for the three per-asset ops only', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    expect($schema['__required_when']['asset_id'] ?? null)
        ->toBe(['get_media', 'get_public_url', 'get_embed_code']);
});

it('keeps `asset_id` required when any per-asset op is allowed', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    foreach (['get_media', 'get_public_url', 'get_embed_code'] as $op) {
        $filtered = OperationSchemaFilter::filter($schema, [$op], 'action');
        expect($filtered['required'])->toContain('asset_id');
    }
});

it('drops `asset_id` from required[] when only `search` is allowed', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    // Single-op: discriminator is stripped from required[] (back-compat).
    $searchOnly = OperationSchemaFilter::filter($schema, ['search'], 'action');
    expect($searchOnly['required'])->not->toContain('asset_id')
        ->and($searchOnly['required'])->not->toContain('action');
});

it('drops `asset_id` from required[] when the agent has no ops allowed', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    $empty = OperationSchemaFilter::filter($schema, [], 'action');
    expect($empty['required'])->not->toContain('asset_id');
});

it('keeps `asset_id` required when search is allowed alongside any per-asset op', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    $mixed = OperationSchemaFilter::filter(
        $schema,
        ['search', 'get_media', 'get_public_url', 'get_embed_code'],
        'action',
    );
    expect($mixed['required'])->toContain('asset_id', 'action');
});

it('strips the __required_when side channel after narrowing', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);
    expect($schema)->toHaveKey('__required_when');

    $filtered = OperationSchemaFilter::filter($schema, ['get_media'], 'action');
    expect($filtered)->not->toHaveKey('__required_when');
});
