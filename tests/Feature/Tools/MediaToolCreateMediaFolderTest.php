<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalContext;

/**
 * `create_media` with a `folder_id`.
 *
 * The op files the new asset in a folder. Filenames are unique within a
 * folder, so a name the folder already holds is refused — and the refusal
 * is a message the assistant can act on, not an exception that ends the
 * turn.
 */
it('files the new asset in the named folder', function (): void {
    $agentId = seedMediaToolAgent();
    $principalId = createUserPrincipalPublic(701);
    $folder = (new MediaFolderService())->create($principalId, 'Corporate');
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'theme.yml',
                'content'   => "name: Corporate\n",
                'folder_id' => (string) $folder->id,
            ],
            agentId: $agentId,
            context: new PrincipalContext($principalId, 'user', 1, 1),
        );

        expect($result->success)->toBeTrue();

        $row = Capsule::table('media_assets')->where('id', $result->data['asset_id'])->first();
        expect((string) $row->folder_id)->toBe((string) $folder->id);
    } finally {
        $restore();
    }
});

it('refuses a filename the folder already holds', function (): void {
    $agentId = seedMediaToolAgent();
    $principalId = createUserPrincipalPublic(702);
    $folder = (new MediaFolderService())->create($principalId, 'Corporate');
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    $context = new PrincipalContext($principalId, 'user', 1, 1);

    try {
        $first = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'theme.yml',
                'content'   => "name: Corporate\n",
                'folder_id' => (string) $folder->id,
            ],
            agentId: $agentId,
            context: $context,
        );
        expect($first->success)->toBeTrue();

        $second = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'theme.yml',
                'content'   => "name: Other\n",
                'folder_id' => (string) $folder->id,
            ],
            agentId: $agentId,
            context: $context,
        );

        expect($second->success)->toBeFalse();
        expect($second->content)->toContain('theme.yml');
        // The refusal is a message, not an exception: the turn continues.
        expect($second->content)->toContain('could not file the document');
    } finally {
        $restore();
    }
});

it('refuses a folder that is not the calling principal\'s', function (): void {
    $agentId = seedMediaToolAgent();
    $ownerId = createUserPrincipalPublic(703);
    $principalId = createUserPrincipalPublic(704);
    $folder = (new MediaFolderService())->create($ownerId, 'Corporate');
    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());

    try {
        $result = $tool->execute(
            [
                'action'    => 'create_media',
                'filename'  => 'theme.yml',
                'content'   => "name: Corporate\n",
                'folder_id' => (string) $folder->id,
            ],
            agentId: $agentId,
            context: new PrincipalContext($principalId, 'user', 1, 1),
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('could not file the document');
    } finally {
        $restore();
    }
});
