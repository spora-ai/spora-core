<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalContext;

/**
 * The rows of a read operation have to reach the model.
 *
 * `ToolResult::$data` never does: it is written to
 * `tool_calls.result_data` for the operator's UI and the audit trail,
 * and the orchestrator hands the model `$result->content` alone
 * ({@see \Spora\Agents\ToolCallExecutor::executeAndRecordResult()}).
 * A read operation that leaves its rows in the data channel therefore
 * answers with a count and nothing else — and a model that cannot see
 * the ids has to guess them, which is how an agent ends up calling
 * `search_assets` with a folder id it never received.
 *
 * These tests pin the other half of that contract: the ids, the names
 * and the urls are on the content channel, one line each.
 */
afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

it('puts the asset rows of a search into the content', function (): void {
    $agentA = seedMediaToolAgent();
    $asset  = seedMediaAsset(
        agentId: $agentA,
        userId: 99,
        idOverride: 'aaaaaaaa-1111-2222-3333-444444444444',
    );

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    try {
        $result = $tool->execute(['action' => 'search'], agentId: $agentA);

        expect($result->success)->toBeTrue();
        // The count line is the summary; the row is the handle.
        expect($result->content)->toContain('Found 1 media asset(s).');
        expect($result->content)->toContain($asset->id);
        expect($result->content)->toContain('sample.png');
        expect($result->content)->toContain('image/png');
    } finally {
        $restore();
    }
});

it('says how many rows a search returned and how many match', function (): void {
    // The count is the number of matches, not the number of rows: a page
    // of 1 out of 3 has to say so, or the model reads "Found 3" as three
    // rows and pages from the end of the list it was given.
    $agentA = seedMediaToolAgent();
    for ($i = 0; $i < 3; $i++) {
        seedMediaAsset(
            agentId: $agentA,
            userId: 99,
            idOverride: sprintf('00000000-0000-0000-0000-%012x', $i),
        );
    }

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    try {
        $result = $tool->execute(['action' => 'search', 'limit' => 1], agentId: $agentA);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Found 3 media asset(s).');
        expect($result->content)->toContain('Showing 1 of 3');
        expect($result->content)->toContain('offset: 1');
    } finally {
        $restore();
    }
});

it('puts the folder rows of a list_folders into the content', function (): void {
    $principalId = createUserPrincipalPublic(8801);
    $folder      = (new MediaFolderService())->create($principalId, 'Corporate');

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    try {
        $result = $tool->execute(
            ['action' => 'list_folders'],
            agentId: 1,
            context: new PrincipalContext($principalId, 'user', 1, 1),
        );

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Found 1 media folder(s).');
        // The folder id is what the folder ops take; the path is what
        // the operator reads.
        expect($result->content)->toContain((string) $folder->id);
        expect($result->content)->toContain('Corporate');
        expect($result->content)->toContain('/Corporate');
    } finally {
        $restore();
    }
});

it('puts the asset rows of a search_assets into the content', function (): void {
    $principalId = createUserPrincipalPublic(8802);
    $folder      = (new MediaFolderService())->create($principalId, 'Corporate');

    $asset = new MediaAsset();
    $asset->id           = 'bbbbbbbb-1111-2222-3333-444444444444';
    $asset->principal_id = $principalId;
    $asset->filename     = 'theme.yml';
    $asset->media_type   = 'document';
    $asset->mime_type    = 'text/markdown';
    $asset->storage_mode = 'data_url';
    $asset->asset_url    = '/api/v1/assets/' . $asset->id . '.md';
    $asset->asset_token  = bin2hex(random_bytes(16));
    $asset->save();
    (new MediaFolderService())->fileAsset($principalId, $asset, (string) $folder->id);

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    try {
        $result = $tool->execute(
            ['action' => 'search_assets', 'folder_id' => (string) $folder->id],
            agentId: 1,
            context: new PrincipalContext($principalId, 'user', 1, 1),
        );

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Found 1 asset(s) in folder');
        expect($result->content)->toContain($asset->id);
        expect($result->content)->toContain('theme.yml');
    } finally {
        $restore();
    }
});

it('puts the derivative rows of a list_derivatives into the content', function (): void {
    $parentId = 'cccccccc-1111-2222-3333-444444444444';
    $childId  = 'cccccccc-1111-2222-3333-aaaaaaaaaaaa';
    $agentId  = seedMediaToolAgent();

    $parent = seedMediaAsset(agentId: $agentId, userId: 99, mime: 'image/png', idOverride: $parentId);
    $parent->byte_size = 32;
    $parent->payload   = str_repeat("\x89PNG", 8);
    $parent->save();

    $child = seedMediaAsset(agentId: null, userId: 99, mime: 'application/pdf', idOverride: $childId);
    $child->byte_size = 1024;
    $child->payload   = '%PDF-fake';
    $child->save();

    Capsule::table('media_derivatives')->insert([
        'id'                 => testGenerateUuidV4(),
        'parent_id'          => $parentId,
        'derivative_id'      => $childId,
        'format'             => 'pdf',
        'producer_plugin'    => 'fake-derivative-producer',
        'producer_operation' => 'render',
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    try {
        $result = $tool->execute(['action' => 'list_derivatives', 'asset_id' => $parentId], agentId: 1);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Found 1 derivative(s)');
        expect($result->content)->toContain($childId);
        expect($result->content)->toContain('pdf');
        expect($result->content)->toContain('/api/v1/assets/' . $childId);
    } finally {
        $restore();
    }
});

it('puts the derivative rows of a get_media into the content', function (): void {
    // `get_media` is the one round-trip that answers "what renders does
    // this source have?" — and the answer was on the data channel
    // alone, so the model had to make a second call to learn the ids it
    // was already holding.
    $parentId = 'dddddddd-1111-2222-3333-444444444444';
    $childId  = 'dddddddd-1111-2222-3333-aaaaaaaaaaaa';
    $agentId  = seedMediaToolAgent();

    $parent = seedMediaAsset(agentId: $agentId, userId: 99, mime: 'image/png', idOverride: $parentId);
    $parent->byte_size = 32;
    $parent->payload   = str_repeat("\x89PNG", 8);
    $parent->save();

    $child = seedMediaAsset(agentId: null, userId: 99, mime: 'application/pdf', idOverride: $childId);
    $child->byte_size = 1024;
    $child->payload   = '%PDF-fake';
    $child->save();

    Capsule::table('media_derivatives')->insert([
        'id'                 => testGenerateUuidV4(),
        'parent_id'          => $parentId,
        'derivative_id'      => $childId,
        'format'             => 'pdf',
        'producer_plugin'    => 'fake-derivative-producer',
        'producer_operation' => 'render',
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    try {
        $result = $tool->execute(['action' => 'get_media', 'asset_id' => $parentId], agentId: 1);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Derivatives of this asset:');
        expect($result->content)->toContain($childId);
        expect($result->content)->toContain('pdf');
    } finally {
        $restore();
    }
});

it('leaves the derivative section out of a get_media that has no derivatives', function (): void {
    $assetId = 'eeeeeeee-1111-2222-3333-444444444444';
    $agentId = seedMediaToolAgent();
    seedMediaAsset(agentId: $agentId, userId: 99, idOverride: $assetId);

    ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
    try {
        $result = $tool->execute(['action' => 'get_media', 'asset_id' => $assetId], agentId: 1);

        expect($result->success)->toBeTrue();
        expect($result->content)->not->toContain('Derivatives of this asset:');
    } finally {
        $restore();
    }
});
