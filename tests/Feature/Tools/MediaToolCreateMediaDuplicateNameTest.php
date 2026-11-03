<?php

declare(strict_types=1);

use Spora\Models\MediaAsset;

/**
 * `create_media` and the "one filename per folder" rule.
 *
 * The archive's rule is that filenames are unique within a folder, and
 * the folder ops enforce it: `file_asset` refuses a name the folder
 * already holds. `create_media` did not, so two calls with the same
 * name left two rows that no folder would ever accept — the second one
 * could not be filed anywhere, and there was no way to rename an asset.
 *
 * The gap lives in the root. The rule is backed by a UNIQUE
 * `(folder_id, filename)` index, and a SQL unique index does not
 * compare NULLs — so the root, whose rows carry a NULL folder, is
 * unconstrained by it. A `create_media` that names a folder is already
 * covered (the ingest writes it through the folder service).
 */
it('refuses a second create_media with a filename the root already holds', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $first = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'report.md',
                'content'  => '# first',
            ],
            agentId: $agentId,
        );
        expect($first->success)->toBeTrue();

        $second = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'report.md',
                'content'  => '# second',
            ],
            agentId: $agentId,
        );

        expect($second->success)->toBeFalse();
        expect($second->content)->toContain('already holds a file named "report.md"');
        // The message has to say what to do next: the op is not
        // idempotent, so the agent has to find the row its earlier call
        // created rather than call again.
        expect($second->content)->toContain('`search`');
        expect($second->content)->toContain('newest row');

        // One row, not two: the refused call leaves nothing behind.
        expect(MediaAsset::query()->where('filename', 'report.md')->count())->toBe(1);
        expect(MediaAsset::query()->find($first->data['asset_id']))->not->toBeNull();
    } finally {
        $restore();
    }
});

it('lets a different filename through in the same folder', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        foreach (['report.md', 'report-2.md'] as $filename) {
            $result = $tool->execute(
                [
                    'action'   => 'create_media',
                    'filename' => $filename,
                    'content'  => '# body',
                ],
                agentId: $agentId,
            );

            expect($result->success)->toBeTrue();
        }

        expect(MediaAsset::query()->whereIn('filename', ['report.md', 'report-2.md'])->count())->toBe(2);
    } finally {
        $restore();
    }
});

it('lets another principal hold the same filename in its own root', function (): void {
    // The rule is per folder, and a folder belongs to a principal — so
    // two principals may each hold a `report.md`. The check is scoped
    // by the principal the archive resolved for the row.
    $firstAgent  = seedMediaToolAgent();
    $secondAgent = seedMediaToolAgent();

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $first = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'report.md',
                'content'  => '# first',
            ],
            agentId: $firstAgent,
        );
        $second = $tool->execute(
            [
                'action'   => 'create_media',
                'filename' => 'report.md',
                'content'  => '# second',
            ],
            agentId: $secondAgent,
        );

        expect($first->success)->toBeTrue();
        expect($second->success)->toBeTrue();
        expect(MediaAsset::query()->where('filename', 'report.md')->count())->toBe(2);
    } finally {
        $restore();
    }
});
