<?php

declare(strict_types=1);


use Spora\Models\MediaAsset;

/**
 * End-to-end tests for `MediaTool::get_source` — the op that surfaces
 * the source of a single media_assets row so the LLM can iterate
 * (re-typeset a previously uploaded `.typ` source, re-ingest an
 * extracted document, etc.). Text-shaped mimes get inline bytes
 * (capped at GET_SOURCE_TEXT_MAX); binary mimes fall back to the
 * asset's extracted `markdown_content` preview.
 *
 * Tests run with admin auth so the scope check is bypassed and the
 * behaviour under test is the read pipeline + size cap + storage_mode
 * branching. Scope failures are covered by `MediaToolScopeTest` and
 * the orchestrator-level wiring tests.
 */
describe('MediaTool::get_source', function (): void {
    /**
     * Seed an asset whose payload sits in the BLOB column (`storage_mode
     * = data_url`) so `DatabaseAssetStore::read()` can return it.
     */
    function seedSourceAsset(
        string $id,
        string $bytes,
        string $mime,
        ?int $userId = 99,
    ): MediaAsset {
        $agentA = seedMediaToolAgent();
        $asset  = seedMediaAsset(
            agentId: $agentA,
            userId: $userId,
            mime: $mime,
            idOverride: $id,
        );
        $asset->byte_size = strlen($bytes);
        $asset->payload   = $bytes;
        $asset->save();
        return $asset;
    }

    it('returns text-shaped bytes inline with a header + utf-8 encoding marker', function (): void {
        $bytes = "= Hello\nThis is a typst source with unicode — and an em-dash.\n";
        seedSourceAsset(
            id: 'aaaaaaaa-1111-2222-3333-444444444444',
            bytes: $bytes,
            mime: 'application/x-typst',
        );

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_source', 'asset_id' => 'aaaaaaaa-1111-2222-3333-444444444444'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toContain('Source of aaaaaaaa-1111-2222-3333-444444444444');
            expect($result->content)->toContain($bytes);
            expect($result->data['encoding'])->toBe('utf-8');
            expect($result->data['byte_size'])->toBe(strlen($bytes));
            expect($result->data['mime_type'])->toBe('application/x-typst');
            expect($result->data)->not->toHaveKey('content_base64');
        } finally {
            $restore();
        }
    });

    it('returns the asset\'s markdown_content for binary mimes when an extracted preview exists', function (): void {
        // Small PNG header — not a valid PNG but enough to prove the
        // binary branch is hit (mime doesn't start with text/).
        $bytes = "\x89PNG\r\n\x1a\n" . random_bytes(32);
        $extractedText = "# Screenshot\n\nOperator-curated description of the binary asset.";
        $asset = seedSourceAsset(
            id: 'bbbbbbbb-1111-2222-3333-444444444444',
            bytes: $bytes,
            mime: 'image/png',
        );
        $asset->markdown_content = $extractedText;
        $asset->save();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_source', 'asset_id' => 'bbbbbbbb-1111-2222-3333-444444444444'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            // Warning prefix so the LLM knows this isn't the raw bytes.
            expect($result->content)->toContain('⚠');
            expect($result->content)->toContain('binary mime');
            expect($result->content)->toContain('markdown_content');
            expect($result->content)->toContain($extractedText);
            // Wire shape pins the fallback contract.
            expect($result->data['fallback'])->toBe('markdown_content');
            expect($result->data['mime_type'])->toBe('image/png');
            expect($result->data['content'])->toBe($extractedText);
            expect($result->data['truncated'])->toBeFalse();
            expect($result->data)->not->toHaveKey('content_base64');
            expect($result->data)->not->toHaveKey('encoding');
        } finally {
            $restore();
        }
    });

    it('truncates a long markdown_content to GET_MEDIA_MARKDOWN_PREVIEW_BYTES and flags it', function (): void {
        $bytes = random_bytes(64);
        $longText = str_repeat('Sentence. ', 4000); // ~44 KB, well over 8 KB
        $asset = seedSourceAsset(
            id: 'b2bbbbbb-1111-2222-3333-444444444444',
            bytes: $bytes,
            mime: 'application/pdf',
        );
        $asset->markdown_content = $longText;
        $asset->save();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_source', 'asset_id' => 'b2bbbbbb-1111-2222-3333-444444444444'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['truncated'])->toBeTrue();
            expect($result->content)->toContain('truncated to');
            // The preview is the truncated string, not the full text.
            expect(strlen((string) $result->data['content']))->toBeLessThan(strlen($longText));
        } finally {
            $restore();
        }
    });

    it('fails with a get_media / list_derivatives hint when a binary asset has no markdown_content', function (): void {
        $bytes = random_bytes(64);
        $asset = seedSourceAsset(
            id: 'dddddddd-1111-2222-3333-444444444444',
            bytes: $bytes,
            mime: 'image/png',
        );
        $asset->markdown_content = null;
        $asset->save();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_source', 'asset_id' => 'dddddddd-1111-2222-3333-444444444444'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('binary mime');
            expect($result->content)->toContain('does not return raw bytes');
            expect($result->content)->toContain('get_media');
            expect($result->content)->toContain('list_derivatives');
        } finally {
            $restore();
        }
    });

    it('refuses text-shaped payloads above GET_SOURCE_TEXT_MAX with a get_media hint', function (): void {
        // Build a payload that's just over the 5 MiB text cap.
        $bytes = str_repeat('a', (5 * 1024 * 1024) + 1);
        seedSourceAsset(
            id: 'cccccccc-1111-2222-3333-444444444444',
            bytes: $bytes,
            mime: 'text/plain',
        );

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_source', 'asset_id' => 'cccccccc-1111-2222-3333-444444444444'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('exceeds the inline `get_source`');
            expect($result->content)->toContain('get_media');
            expect($result->content)->toContain('5 MiB');
        } finally {
            $restore();
        }
    });

    it('returns the asset-not-found error when asset_id is unknown', function (): void {
        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_source', 'asset_id' => 'ffffffff-1111-2222-3333-444444444444'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toBe('Media asset not found.');
        } finally {
            $restore();
        }
    });

    it('fails with an external-storage hint when storage_mode is external', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $agentA, userId: 99, idOverride: 'eeeeeeee-1111-2222-3333-444444444444');
        // Flip to external; seedMediaAsset defaults to data_url.
        $asset->storage_mode = 'external';
        $asset->source_url   = 'https://example.invalid/source.typ';
        $asset->payload      = null;
        $asset->save();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_source', 'asset_id' => 'eeeeeeee-1111-2222-3333-444444444444'],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('storage_mode=external');
            expect($result->content)->toContain('get_media');
        } finally {
            $restore();
        }
    });

    it('describes the action as "Media get_source(<id>)"', function (): void {
        ['tool' => $tool] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

        expect($tool->describeAction(['action' => 'get_source', 'asset_id' => 'foo']))
            ->toBe('Media get_source(foo)');
    });

    it('exposes the get_source op in the tool name + description surface', function (): void {
        // Pin via the OpenAPI-shape definition the orchestrator emits —
        // the schema is the contract the LLM sees.
        $tool = buildMediaToolForSchema();
        $schema = $tool->getParametersSchema();
        $enum   = $schema['properties']['action']['enum'];

        expect($enum)->toContain('get_source');
        // asset_id is required when get_source is in the allowed set
        $filtered = Spora\Tools\Schema\OperationSchemaFilter::filter(
            $schema,
            ['get_source'],
            'action',
        );
        expect($filtered['required'])->toContain('asset_id');
    });
});
