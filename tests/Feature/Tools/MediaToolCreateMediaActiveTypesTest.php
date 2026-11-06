<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Config;
use Spora\Services\MediaArchive\MediaActiveTypesPolicy;

/**
 * The operator's script-capable toggle, seen through `create_media`.
 *
 * `text/html` was in the static text allowlist until this toggle existed,
 * which meant any install could have a `<script>` payload stored as HTML and
 * served back. The toggle moves it behind an explicit opt-in.
 */
afterEach(function (): void {
    Capsule::table('media_assets')->delete();
});

it('refuses an HTML payload while the operator has not permitted it', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'page.html',
                'mime_type' => 'text/html',
                'content'   => '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>',
            ],
            agentId: $agentId,
        );

        expect($result->success)->toBeFalse();
        expect(Capsule::table('media_assets')->count())->toBe(0);
    } finally {
        $restore();
    }
});

it('accepts an HTML payload once the operator has permitted it', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true),
    );

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'page.html',
                'mime_type' => 'text/html',
                'content'   => '<!DOCTYPE html><html><body><h1>Hello</h1></body></html>',
            ],
            agentId: $agentId,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['mime_type'])->toBe('text/html');
        expect(Capsule::table('media_assets')->count())->toBe(1);
    } finally {
        $restore();
    }
});

it('describes the embed only for a permitted script-capable asset', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true),
    );

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'page.html',
                'mime_type' => 'text/html',
                'content'   => '<!DOCTYPE html><html><body><h1>Hello</h1></body></html>',
            ],
            agentId: $agentId,
        );

        expect($result->data['embed_descriptor'])->toBeArray();
        expect($result->data['embed_descriptor']['url'])->toContain('/embed');
        expect($result->data['embed_descriptor']['mime'])->toBe('text/html');
        // The embed-script toggle is separate, and off here.
        expect($result->data['embed_descriptor']['scripts'])->toBeFalse();
    } finally {
        $restore();
    }
});

it('describes no embed for an ordinary asset', function (): void {
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true),
    );

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'notes.md',
                'mime_type' => 'text/markdown',
                'content'   => '# Notes',
            ],
            agentId: $agentId,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['embed_descriptor'])->toBeNull();
    } finally {
        $restore();
    }
});

it('stores a Markdown report as Markdown even when its body is markup-dense', function (): void {
    // `finfo` reports prose carrying enough markup as HTML, and HTML is
    // script-capable — so a Markdown report that happened to contain a
    // table was stored as a document and offered to the chat as a live
    // frame. The filename is the author's intent, and it wins.
    $agentId = seedMediaToolAgent();
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
        makeMediaToolAdminAuth(),
        null,
        null,
        mediaActiveTypesPolicy(activeTypes: true),
    );

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'report.md',
                'mime_type' => 'text/markdown',
                'content'   => "# Report\n\n<table><tr><td>Day</td></tr></table>\n<span>done</span>\n",
            ],
            agentId: $agentId,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['mime_type'])->toBe('text/markdown');
        expect($result->data['embed_descriptor'])->toBeNull();
    } finally {
        $restore();
    }
});

/**
 * A policy with the operator's script-capable toggle at a chosen value.
 */
function mediaActiveTypesPolicy(bool $activeTypes, bool $embedScripts = false): MediaActiveTypesPolicy
{
    return new MediaActiveTypesPolicy(new Config(
        [
            'SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES'   => $activeTypes,
            'SPORA_MEDIA_ARCHIVE_ALLOW_EMBED_SCRIPTS' => $embedScripts,
        ],
        [
            'SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES'   => Config::SOURCE_ENV,
            'SPORA_MEDIA_ARCHIVE_ALLOW_EMBED_SCRIPTS' => Config::SOURCE_ENV,
        ],
    ));
}
