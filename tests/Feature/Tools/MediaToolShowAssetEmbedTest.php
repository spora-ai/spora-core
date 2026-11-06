<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The live embed descriptor on the ops that show an **existing** asset.
 *
 * `create_media` has carried it since the feature landed, so an agent that
 * creates a widget and immediately shows it gets a live frame. But the
 * ordinary way to show an asset is `get_media` — and that op carried no
 * descriptor at all, so every widget the agent *re-showed* arrived as a
 * download card.
 *
 * Measured on a live task with thirty media calls: the eight live frames
 * were all on `create_media` results. Every `get_media` of the same assets
 * was a download button.
 */
afterEach(function (): void {
    Capsule::table('media_assets')->delete();
});

it('describes the embed on get_media for a permitted script-capable asset', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true, embedScripts: true),
    );

    try {
        $created = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'dashboard.html',
                'mime_type' => 'text/html',
                'content'   => '<!DOCTYPE html><html><body><h1>Hi</h1></body></html>',
            ],
            agentId: $agentId,
        );
        expect($created->success)->toBeTrue();

        $shown = $tool->execute(
            [
                'action'  => 'get_media',
                'asset_id' => $created->data['asset_id'],
            ],
            agentId: $agentId,
        );

        expect($shown->success)->toBeTrue();
        expect($shown->data['embed_descriptor'])->toBeArray();
        expect($shown->data['embed_descriptor']['url'])->toBe(
            '/api/v1/media/' . $created->data['asset_id'] . '/embed',
        );
        expect($shown->data['embed_descriptor']['mime'])->toBe('text/html');
        expect($shown->data['embed_descriptor']['scripts'])->toBeTrue();
    } finally {
        $restore();
    }
});

it('describes the embed on get_embed_code for a permitted script-capable asset', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true),
    );

    try {
        $created = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'dashboard.html',
                'mime_type' => 'text/html',
                'content'   => '<!DOCTYPE html><html><body><h1>Hi</h1></body></html>',
            ],
            agentId: $agentId,
        );

        $shown = $tool->execute(
            [
                'action'  => 'get_embed_code',
                'asset_id' => $created->data['asset_id'],
            ],
            agentId: $agentId,
        );

        expect($shown->data['embed_descriptor'])->toBeArray();
        expect($shown->data['embed_descriptor']['mime'])->toBe('text/html');
    } finally {
        $restore();
    }
});

it('describes no embed on get_media for an ordinary asset', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true),
    );

    try {
        $created = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'notes.md',
                'mime_type' => 'text/markdown',
                'content'   => '# Notes',
            ],
            agentId: $agentId,
        );

        $shown = $tool->execute(
            [
                'action'  => 'get_media',
                'asset_id' => $created->data['asset_id'],
            ],
            agentId: $agentId,
        );

        expect($shown->data['embed_descriptor'])->toBeNull();
    } finally {
        $restore();
    }
});

it('describes no embed on get_media while the operator has not permitted script-capable assets', function (): void {
    // The descriptor is the operator's to give, so the read path honours the
    // same switch the create path does: an asset that exists is not enough
    // for a read to hand out an embed.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true),
    );

    try {
        $created = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'dashboard.html',
                'mime_type' => 'text/html',
                'content'   => '<!DOCTYPE html><html><body><h1>Hi</h1></body></html>',
            ],
            agentId: $agentId,
        );
        expect($created->success)->toBeTrue();

        ['tool' => $offTool, 'restore' => $offRestore] = makeMediaToolWithRealArchive(
            makeMediaToolAdminAuth(),
            null,
            null,
            mediaActiveTypesPolicy(activeTypes: false),
        );

        try {
            $shown = $offTool->execute(
                [
                    'action'  => 'get_media',
                    'asset_id' => $created->data['asset_id'],
                ],
                agentId: $agentId,
            );

            expect($shown->success)->toBeTrue();
            expect($shown->data['embed_descriptor'])->toBeNull();
        } finally {
            $offRestore();
        }
    } finally {
        $restore();
    }
});
