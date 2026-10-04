<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaAllowedTypesService;

/**
 * The two-gate allowlist contract on `MediaTool`'s `create_media` op.
 *
 * `MediaIngestRequest::$mime` is read **only** on the URL branch
 * (`MediaArchiveUrlResolver`), so `ingestFromBytes()` always re-sniffs the
 * payload and stores what `MimeSniffer` / `MetadataExtractor` found. The
 * declared `mime_type` is therefore a hint, never a claim, and the row has
 * to be re-gated on the value that actually landed in the DB.
 *
 * Both gates matter, and they are not redundant:
 *
 *   1. Pre-gate on the hint — a MIME the operator would never have
 *      accepted fails before any bytes are written. Cheap, and it keeps
 *      the archive clean.
 *   2. Re-gate on the sniffed MIME — the only gate that stops a *good*
 *      hint over *bad* bytes. This is the security-relevant one: without
 *      it, an LLM could smuggle a binary payload into the archive by
 *      labelling it `text/markdown`.
 *
 * The handler's response to a failed re-gate is to delete the row, so
 * these tests assert the row count, not just the `ToolResult`.
 */

afterEach(function (): void {
    Capsule::table('media_assets')->delete();
});

function mediaAssetRowCount(): int
{
    return Capsule::table('media_assets')->count();
}

it('fails on a disallowed mime hint before anything is ingested', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'payload.md',
                'mime_type' => 'application/x-msdownload',
                'content'   => '# harmless looking markdown',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeFalse();
        expect($result->content)
            ->toContain('`create_media` does not accept mime_type "application/x-msdownload"')
            // The allowed list, so the model can retry without guessing.
            ->toContain('text/markdown')
            ->toContain('text/plain');

        // Pre-gate means pre-storage: no row, no payload, nothing to clean up.
        expect(mediaAssetRowCount())->toBe(0);
    } finally {
        $restore();
    }
});

it('fails on a disallowed mime hint even when the content is plain text', function (): void {
    // The pre-gate judges the declared type, not the bytes — an operator
    // who allowlists only text still gets a rejection for a claim that
    // does not appear on the list.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'payload.md',
                'mime_type' => 'image/svg+xml',
                'content'   => 'just words',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeFalse();
        expect(mediaAssetRowCount())->toBe(0);
    } finally {
        $restore();
    }
});

it('deletes and rejects a hint that passes but whose bytes sniff to a disallowed MIME', function (): void {
    // The important case. `text/markdown` is allowlisted, the hint sails
    // through the pre-gate, and then `ingestFromBytes()` re-sniffs the
    // bytes and stores a type the operator's allowlist excludes (see
    // `makeMediaCreateHandlerForTest()`).
    //
    // A pass on the hint must NOT be treated as a pass on the payload.
    //
    // Note the sniffed value is `application/octet-stream`, not
    // `image/png`: the handler UTF-8-scrubs `content` before ingest, which
    // destroys a raw binary magic header. So the smuggled bytes degrade to
    // the sniffer's unrecognised fallback — which is on nobody's allowlist.
    // The gate is therefore not merely advisory: a payload that cannot
    // even be identified never lands in the archive under a text claim.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00\x01", 32);

        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'totally-markdown.md',
                'mime_type' => 'text/markdown',
                'content'   => $png,
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeFalse();
        // The failure names the type that was actually stored, not the
        // one the caller claimed — otherwise the model retries forever
        // with the same wrong hint.
        expect($result->content)
            ->toContain('rejected and discarded the stored row')
            ->toContain('application/octet-stream');

        // The row is gone, not merely hidden from the response.
        expect(mediaAssetRowCount())->toBe(0);
    } finally {
        $restore();
    }
});

it('leaves no orphan rows behind when several smuggled payloads are rejected', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $payloads = [
            "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 24),
            "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 24),
            str_repeat("\x00", 40),
        ];

        foreach ($payloads as $index => $bytes) {
            $result = $tool->execute(
                [
                    'action'    => 'create_media',
                    'filename'  => "smuggle-{$index}.md",
                    'mime_type' => 'text/plain',
                    'content'   => $bytes,
                ],
                agentId: $agentId,
                userId: 99,
            );
            expect($result->success)->toBeFalse();
        }

        expect(mediaAssetRowCount())->toBe(0);
    } finally {
        $restore();
    }
});

it('still admits audio bytes, because the audio allowlist is unconditional', function (): void {
    // The control on the previous two cases: not every binary payload is
    // a rejection. `MediaAllowedTypesService::AUDIO_MIME_TYPES` is
    // operator-unconditional (the STT pipeline needs it), so an ID3 header
    // under a text claim is admitted as `audio/mpeg`. `create_media` does
    // not second-guess that policy — it enforces the allowlist it is given.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'notes.md',
                'mime_type' => 'text/plain',
                'content'   => 'ID3' . str_repeat("\x00", 24),
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['mime_type'])->toBe('audio/mpeg');
        expect(mediaAssetRowCount())->toBe(1);

        // An audio asset must render through the shared taxonomy, so
        // `create_media` shows the same `<audio>` player a later
        // `get_media` on this asset would. Hard-coding the card here
        // instead is how an asset ends up a player on one operation and a
        // download box on the next.
        expect($result->content)->toContain('<audio');
        expect($result->content)->not->toContain('spora-file-card');

        $fetched = $tool->execute(
            ['action' => 'get_media', 'asset_id' => $result->data['asset_id']],
            agentId: $agentId,
            userId: 99,
        );

        expect($fetched->success)->toBeTrue();
        expect($fetched->content)->toContain('<audio');
    } finally {
        $restore();
    }
});

it('accepts a hint that matches what the bytes actually sniff to', function (): void {
    // The control case: the re-gate must not fire when the sniffed type
    // is genuinely on the allowlist, or `create_media` would be unusable.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'notes.md',
                'mime_type' => 'text/plain',
                'content'   => "plain text notes\n",
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect(mediaAssetRowCount())->toBe(1);
        // Stored as `text/markdown`, not the hinted `text/plain`: the `.md`
        // filename refines the sniffed verdict, and the re-gate is applied to
        // the stored value. The point of the case is unchanged — a type the
        // bytes sniff to is on the allowlist, so nothing is rejected.
        expect(MediaAsset::query()->find($result->data['asset_id'])->mime_type)->toBe('text/markdown');
    } finally {
        $restore();
    }
});

it('reports the sniffed mime as authoritative when it differs from the hint', function (): void {
    // `data.mime_type` is the only value the caller can trust — a JSON
    // payload declared as `text/markdown` is stored as whatever
    // `finfo` says, and the skill tells the model to read it back.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'data.json',
                'mime_type' => 'text/markdown',
                'content'   => '{"quarter": 3}',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['mime_type'])->not->toBe('text/markdown');
        expect($result->data['mime_type'])->toBeString();
    } finally {
        $restore();
    }
});

it('lists the allowed mimes on a rejection so the model can retry', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'payload.md',
                'mime_type' => 'application/x-sh',
                'content'   => 'text',
            ],
            agentId: $agentId,
            userId: 99,
        );

        expect($result->success)->toBeFalse();
        foreach (MediaAllowedTypesService::TEXT_MIME_TYPES as $mime) {
            expect($result->content)->toContain($mime);
        }
    } finally {
        $restore();
    }
});
