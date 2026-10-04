<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;

/**
 * End-to-end coverage for `MediaTool`'s `create_media` operation — the
 * one write that needs no existing parent, and therefore the primitive
 * every text-parent derivative producer builds on (author Markdown →
 * `create_derivative` → DOCX/PDF render).
 *
 * The op runs against the real ingest pipeline, so these assertions
 * cover the whole chain and not just the tool's return value: the
 * `media_assets` row, the `plugin_slug` / `tool_name` / `upload_source`
 * attribution that tells the operator dashboard which tool minted the
 * file, and the absence of any derivative row — authored text is its own
 * text, so the bytes are stored exactly once and read straight back out
 * through `get_source` on a later turn.
 *
 * Tests run with admin auth so the scope gate is bypassed; the scope
 * check itself is `MediaToolScopeTest`'s job.
 */

it('stores authored text and returns the asset id, url, and a download card', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'quarterly-report.md',
                'content'  => "# Q3\n\nRevenue up 12%.\n",
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data)->toHaveKeys(['asset_id', 'asset_url', 'filename', 'mime_type', 'byte_size']);
        expect($result->data['filename'])->toBe('quarterly-report.md');
        expect($result->data['byte_size'])->toBe(strlen("# Q3\n\nRevenue up 12%.\n"));
        expect($result->data['asset_url'])->toContain($result->data['asset_id']);
        // No `op` discriminator and no `files` array: the card comes from
        // the content channel, so `data` stays a flat metadata bag.
        expect($result->data)->not->toHaveKey('op', 'files');

        expect($result->content)->toContain("Media asset {$result->data['asset_id']}: quarterly-report.md")
            ->and($result->content)->toContain('spora-file-card__glyph')
            ->and($result->content)->toContain('>quarterly-report.md</span>')
            ->and($result->content)->toContain("href=\"{$result->data['asset_url']}\"")
            ->and($result->content)->toContain('verbatim')
            ->and($result->content)->toContain('not idempotent');
    } finally {
        $restore();
    }
});

it('attributes the row to the core media tool, not to a plugin', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'notes.md',
                'content'  => 'scratch notes',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();

        $row = Capsule::table('media_assets')->where('id', $result->data['asset_id'])->first();
        expect($row)->not->toBeNull();
        expect((string) $row->plugin_slug)->toBe('spora-core');
        expect((string) $row->tool_name)->toBe('create_media');
        expect((string) $row->upload_source)->toBe('tool');
        expect((int) $row->agent_id)->toBe($agentId);
    } finally {
        $restore();
    }
});

it('stores authored text once, with no derivative of it', function (): void {
    // The duplicate-storage bug this change removes: the row is the
    // storage, and `get_source` reads the same bytes back on a later turn
    // without the LLM having to keep the original string in its context.
    // Asserting the *absence* of a derivative is the part that bites —
    // minting `quarterly-report.md` of `quarterly-report.md` would
    // silently double every authored document's footprint.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $markdown = "# Q3\n\nRevenue up 12%.\n";
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'quarterly-report.md',
                'content'  => $markdown,
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        $assetId = (string) $result->data['asset_id'];
        expect(MediaDerivative::query()->where('parent_id', $assetId)->count())->toBe(0);

        // And the bytes are readable straight back off the source row.
        $sourceResult = $tool->execute(
            ['action' => 'get_source', 'asset_id' => $assetId],
            agentId: $agentId,
            userId: 99,
        );
        expect($sourceResult->success)->toBeTrue();
        expect($sourceResult->content)->toContain(trim($markdown));
    } finally {
        $restore();
    }
});

it('records the prompt as provenance', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'notes.md',
                'content'  => 'scratch notes',
                'prompt'   => 'Q3 revenue summary for the board deck',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['asset_id'])->toBeString();

        $getMedia = $tool->execute(
            ['action' => 'get_media', 'asset_id' => $result->data['asset_id']],
            agentId: $agentId,
            userId: 99,
        );
        expect($getMedia->data['prompt'])->toBe('Q3 revenue summary for the board deck');
    } finally {
        $restore();
    }
});

it('defaults the mime hint to text/markdown when none is given', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'notes',
                'content'  => '# hello',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        // The declared hint is still only a hint — but a `.md` filename now
        // refines the sniffed `text/plain` up to `text/markdown`, which is
        // what lets a producer that advertises `text/markdown` match this
        // parent at all. The bytes alone cannot tell Markdown from prose, so
        // the extension is what the stored type is derived from.
        expect($result->data['mime_type'])->toBe('text/markdown');
        expect($result->data['filename'])->toBe('notes.md');
        expect(MediaAsset::query()->find($result->data['asset_id'])->media_type)->toBe('document');
    } finally {
        $restore();
    }
});

it('round-trips the returned asset_id through get_media under scope: agent', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());

    try {
        $created = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'chain.md',
                'content'  => '# chain',
            ],
            agentId: $agentId,
            userId: 99,
        );
        expect($created->success)->toBeTrue();

        // Non-admin auth: the row is only visible because ingest stamped
        // it with the calling agent, which is the whole scope contract.
        $fetched = $tool->execute(
            ['action' => 'get_media', 'asset_id' => $created->data['asset_id']],
            agentId: $agentId,
            userId: 99,
        );

        expect($fetched->success)->toBeTrue();
        expect($fetched->data['id'])->toBe($created->data['asset_id']);
        expect($fetched->data['filename'])->toBe('chain.md');
        expect($fetched->content)->toContain($created->content ? 'Media asset ' . $created->data['asset_id'] : '');
    } finally {
        $restore();
    }
});

it('rejects empty content', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        foreach ([[], ['content' => ''], ['content' => '   ']] as $arguments) {
            $result = $tool->execute(
                ['action' => 'create_media', 'filename' => 'empty.md'] + $arguments,
                agentId: $agentId,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('`content` is required');
        }

        expect(Capsule::table('media_assets')->count())->toBe(0);
    } finally {
        $restore();
    }
});

it('rejects content over 1 MiB with both byte counts', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $oversize = str_repeat('a', 1024 * 1024 + 1);
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'huge.md',
                'content'  => $oversize,
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeFalse();
        // Both numbers, so the model can size its own retry instead of
        // guessing at the limit.
        expect($result->content)
            ->toContain((string) strlen($oversize))
            ->toContain((string) (1024 * 1024));

        expect(Capsule::table('media_assets')->count())->toBe(0);
    } finally {
        $restore();
    }
});

it('accepts content of exactly 1 MiB', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'exact.md',
                'content'  => str_repeat('a', 1024 * 1024),
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['byte_size'])->toBe(1024 * 1024);
    } finally {
        $restore();
    }
});

it('neutralises a path-traversing filename', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => '../../etc/passwd',
                'content'  => 'nope',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        // The traversal is reduced to the basename, then the hinted
        // `text/markdown` appends `.md` — the extension is added, never a
        // second path segment.
        expect($result->data['filename'])->toBe('passwd.md');
        expect($result->data['filename'])->not->toContain('..');
        expect($result->content)->toContain('>passwd.md</span>');
    } finally {
        $restore();
    }
});

it('strips control characters from the filename', function (): void {
    // The stored filename is echoed into a `Content-Disposition` header on
    // the download route, so a raw C0 control is a header-splitting vector.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => "re\r\nport\x00.md",
                'content'  => 'body',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['filename'])->toBe('report.md');
        expect($result->content)->not->toContain("\r")
            ->and($result->content)->not->toContain("\nX-Injected");
    } finally {
        $restore();
    }
});

it('replaces characters outside the allowlist in the filename', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'weird:name*here?.md',
                'content'  => 'body',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['filename'])->toBe('weird_name_here_.md');
    } finally {
        $restore();
    }
});

it('keeps only the last path segment of a slash-bearing filename', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'reports/2026/q3.md',
                'content'  => 'body',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['filename'])->toBe('q3.md');
    } finally {
        $restore();
    }
});

it('falls back to a name when the filename sanitises to nothing', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => '../../..',
                'content'  => 'body',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        // A dot-only survivor becomes `media`, and the hinted
        // `text/markdown` still appends `.md` — the label is a stem, not an
        // escape hatch from extension handling.
        expect($result->data['filename'])->toBe('media.md');
    } finally {
        $restore();
    }
});

it('caps the filename at the 255-char column width', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => str_repeat('n', 400) . '.md',
                'content'  => 'body',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect(mb_strlen((string) $result->data['filename']))->toBe(255);
        // The extension is what the operator sees in the download's
        // `Content-Disposition`, and truncating the assembled name drops it
        // first — so the cap has to apply to the stem instead.
        expect($result->data['filename'])->toEndWith('.md');
    } finally {
        $restore();
    }
});

it('caps the filename when the extension alone is longer than the column', function (): void {
    // The cap is applied to the stem with a budget of 255 minus the suffix, so
    // a suffix past 255 made that budget negative — and `mb_substr($stem, 0,
    // -46)` returns an empty string, not a clamped one. The name came back at
    // the full 301 chars, over the column width, and the archive rejected it on
    // save, so the caller saw "could not store the document" instead of a
    // filename hint. `pathinfo()` takes everything after the last dot as the
    // extension, which a caller can reach without any unusual bytes.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'report.' . str_repeat('x', 300),
                'content'  => 'body',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect(mb_strlen((string) $result->data['filename']))->toBeLessThanOrEqual(255);
    } finally {
        $restore();
    }
});

it('appends the extension implied by the mime hint when the caller omits one', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'data',
                'mime_type' => 'application/json',
                'content'   => '{"quarter":3}',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['filename'])->toBe('data');
        // `application/json` has no entry in the archive's extension map,
        // so the asset URL stays suffix-free rather than fabricating one.
        expect($result->data['asset_url'])->toBe('/api/v1/assets/' . $result->data['asset_id']);
    } finally {
        $restore();
    }
});

it('describes the action for the approval prompt', function (): void {
    $tool = buildMediaToolForSchema();

    expect($tool->describeAction([
        'action'    => 'create_media',
        'filename'  => 'report.md',
        'mime_type' => 'text/markdown',
    ]))->toBe('Media create_media(report.md, mime=text/markdown)');
});
