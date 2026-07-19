<?php

declare(strict_types=1);


use Spora\Auth\AuthService;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\ToolConfigService;
use Spora\Tools\MediaTool;
use Symfony\Component\HttpFoundation\Request;

/**
 * Create an Agent row so the FK on media_assets.agent_id resolves. The
 * tests use unique email + namespaced agent names so a single Pest run
 * never collides between cases (each test runs in its own transaction).
 */
function seedMediaToolAgent(string $name = 'Test Agent'): int
{
    // Create the user row first to satisfy the agents.user_id FK.
    // The exact id doesn't matter — only the row needs to exist.
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, password, username, verified, resettable, roles_mask, registered, created_at, updated_at) '
        . 'VALUES (?, ?, ?, 1, 1, 0, ?, ?, ?)',
    );
    $email = sprintf('mediatool-%s-%s@example.com', bin2hex(random_bytes(4)), microtime(true));
    $now   = time();
    $stmt->execute([
        $email,
        password_hash('Password1!', PASSWORD_BCRYPT),
        $email,
        $now,
        date('Y-m-d H:i:s', $now),
        date('Y-m-d H:i:s', $now),
    ]);
    $userId = (int) $pdo->lastInsertId();

    return Agent::create([
        'user_id'   => $userId,
        'name'      => $name,
        'is_active' => true,
    ])->id;
}

function seedMediaAsset(
    ?int $agentId = null,
    ?int $userId = null,
    ?string $publicToken = null,
    ?string $pluginSlug = null,
    ?string $mime = 'image/png',
    ?string $idOverride = null,
): MediaAsset {
    $id = $idOverride ?? sprintf(
        '%08x-aaaa-bbbb-cccc-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffffffffffff),
    );

    return MediaAsset::create([
        'id'                            => $id,
        'asset_url'                     => MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.png',
        'storage_mode'                  => 'data_url',
        'mime_type'                     => $mime,
        'media_type'                    => 'image',
        'byte_size'                     => 1024,
        'agent_id'                      => $agentId,
        'user_id'                       => $userId,
        'plugin_slug'                   => $pluginSlug,
        'asset_token'                   => bin2hex(random_bytes(16)),
        'public_access_token'           => $publicToken,
        'filename'                      => 'sample.png',
        'migrated_from_inline_data_url' => false,
    ]);
}

function makeMediaToolNonAdminAuth(): AuthService
{
    $auth = Mockery::mock(AuthService::class);
    $auth->allows('isAdmin')->andReturn(false);
    $auth->allows('currentUserId')->andReturn(null);
    return $auth;
}

function makeMediaToolAdminAuth(): AuthService
{
    $auth = Mockery::mock(AuthService::class);
    $auth->allows('isAdmin')->andReturn(true);
    $auth->allows('currentUserId')->andReturn(1);
    return $auth;
}

/**
 * Build a MediaTool wired to a real MediaArchiveService so the test
 * exercises the same query path the LLM hits in production. Returns the
 * tool + a cleanup closure that wipes the tmp asset dir.
 */
function makeMediaToolWithRealArchive(?AuthService $auth = null, ?ToolConfigService $config = null, ?Request $request = null): array
{
    // Reuse the same builder that MediaArchiveServiceTest uses so we get a
    // fully-wired service (asset store, resolver, converters, decoder).
    $ctx = \Tests\Feature\MediaArchive\makeMediaArchiveService();
    $tool = new MediaTool(
        $ctx['service'],
        $auth ?? makeMediaToolNonAdminAuth(),
        $config,
        $request,
    );

    return ['tool' => $tool, 'restore' => $ctx['restore']];
}

describe('MediaTool::search', function (): void {
    it('returns paginated metadata with asset_url in the opaque local form', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $agentA, userId: 99, idOverride: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search', 'limit' => 10], agentId: $agentA, userId: 99);

            expect($result->success)->toBeTrue();
            expect($result->data)->not->toBeNull();
            expect($result->data['total'])->toBe(1);
            expect($result->data['limit'])->toBe(10);
            expect($result->data['items'][0]['id'])->toBe($asset->id);
            expect($result->data['items'][0]['asset_url'])
                ->toBe('/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png');
            expect($result->data['items'][0]['mime_type'])->toBe('image/png');
        } finally {
            $restore();
        }
    });

    it('clamps limit at the PER_PAGE_MAX ceiling', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search', 'limit' => 999_999], agentId: $agentA, userId: 99);

            expect($result->success)->toBeTrue();
            expect($result->data['limit'])->toBe(ListMediaQuery::PER_PAGE_MAX);
        } finally {
            $restore();
        }
    });

    it('returns empty list when no assets match', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search'], agentId: $agentA, userId: 99);

            expect($result->success)->toBeTrue();
            expect($result->data['total'])->toBe(0);
            expect($result->data['items'])->toBe([]);
        } finally {
            $restore();
        }
    });

    it('filters by plugin_slug', function (): void {
        $agentA = seedMediaToolAgent();
        seedMediaAsset(agentId: $agentA, userId: 99, pluginSlug: 'minimax');
        seedMediaAsset(agentId: $agentA, userId: 99, pluginSlug: 'weather');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search', 'plugin_slug' => 'minimax'], agentId: $agentA, userId: 99);

            expect($result->success)->toBeTrue();
            expect($result->data['total'])->toBe(1);
        } finally {
            $restore();
        }
    });

    it('accepts a mime_type argument without erroring', function (): void {
        // `mime_type` is accepted for LLM ergonomics (the assistant usually
        // has it on hand from prior tool results) but is NOT used as a
        // filter on the underlying ListMediaQuery. The coarse media_type
        // bucket (image/audio/video/document) is what actually filters.
        // This test locks in that passing a non-empty mime_type does not
        // break the query.
        $agentA = seedMediaToolAgent();
        seedMediaAsset(agentId: $agentA, userId: 99);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search', 'mime_type' => 'image/png'], agentId: $agentA, userId: 99);

            expect($result->success)->toBeTrue();
            expect($result->data)->toHaveKey('total');
        } finally {
            $restore();
        }
    });

    it('uses agent_id from agentId when scope=agent (non-admin)', function (): void {
        $mine = seedMediaToolAgent();
        $other = seedMediaToolAgent();
        seedMediaAsset(agentId: $mine, userId: 99);
        seedMediaAsset(agentId: $other, userId: 99);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search'], agentId: $mine, userId: 99);

            expect($result->success)->toBeTrue();
            expect($result->data['total'])->toBe(1);
            expect($result->data['items'][0]['id'])->toBe(MediaAsset::query()->where('agent_id', $mine)->first()->id);
        } finally {
            $restore();
        }
    });

    it('uses user_id from userId when scope=user', function (): void {
        $userConfig = Mockery::mock(ToolConfigService::class);
        $userConfig->allows('getEffectiveSettings')->andReturn(['scope' => 'user']);

        $a1 = seedMediaToolAgent();
        $a2 = seedMediaToolAgent();
        $a3 = seedMediaToolAgent();
        seedMediaAsset(agentId: $a1, userId: 77);
        seedMediaAsset(agentId: $a2, userId: 77);
        seedMediaAsset(agentId: $a3, userId: 88);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth(), $userConfig);
        try {
            $result = $tool->execute(['action' => 'search'], agentId: $a1, userId: 77);

            expect($result->success)->toBeTrue();
            expect($result->data['total'])->toBe(2);
        } finally {
            $restore();
        }
    });

    it('includes media from prior tasks (cross-task scope)', function (): void {
        $agentA = seedMediaToolAgent();
        seedMediaAsset(agentId: $agentA, userId: 99, idOverride: '11111111-1111-1111-1111-111111111111');
        seedMediaAsset(agentId: $agentA, userId: 99, idOverride: '22222222-2222-2222-2222-222222222222');
        seedMediaAsset(agentId: $agentA, userId: 99, idOverride: '33333333-3333-3333-3333-333333333333');
        seedMediaAsset(agentId: $agentA, userId: 99, idOverride: '44444444-4444-4444-4444-444444444444');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search'], agentId: $agentA, userId: 99, taskId: 999);

            expect($result->success)->toBeTrue();
            expect($result->data['total'])->toBe(4);
        } finally {
            $restore();
        }
    });

    it('respects pagination offset', function (): void {
        $agentA = seedMediaToolAgent();
        for ($i = 0; $i < 5; $i++) {
            seedMediaAsset(
                agentId: $agentA,
                userId: 99,
                idOverride: sprintf('00000000-0000-0000-0000-%012x', $i),
            );
        }

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(['action' => 'search', 'limit' => 2, 'offset' => 2], agentId: $agentA, userId: 99);

            expect($result->success)->toBeTrue();
            expect($result->data['limit'])->toBe(2);
            expect($result->data['offset'])->toBe(2);
        } finally {
            $restore();
        }
    });
});

describe('MediaTool::get_media', function (): void {
    it('returns the asset for the current agent (scope=agent)', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $agentA, userId: 99);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['id'])->toBe($asset->id);
            expect($result->data['asset_url'])->toContain($asset->id);
        } finally {
            $restore();
        }
    });

    it('returns the asset for any of the user\'s agents when scope=user', function (): void {
        $otherAgent = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $otherAgent, userId: 42);
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->andReturn(['scope' => 'user']);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth(), $config);
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $asset->id],
                agentId: 99,
                userId: 42,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['id'])->toBe($asset->id);
        } finally {
            $restore();
        }
    });

    it('returns 404 for an asset owned by another agent (scope=agent, non-admin)', function (): void {
        $ownerAgent = seedMediaToolAgent();
        $otherAgent = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $ownerAgent, userId: 99);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $asset->id],
                agentId: $otherAgent,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('returns 404 for an asset owned by another user (scope=user)', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $agentA, userId: 42);
        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->andReturn(['scope' => 'user']);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth(), $config);
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $asset->id],
                agentId: 99,
                userId: 88,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('returns failure when asset_id is missing', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(['action' => 'get_media'], agentId: $agentA, userId: 99);

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('asset_id is required');
        } finally {
            $restore();
        }
    });

    it('returns 404 when the asset does not exist', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => '99999999-9999-9999-9999-999999999999'],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('bypasses scope for admins', function (): void {
        $otherAgent = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $otherAgent, userId: 1);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $asset->id],
                agentId: 1,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['id'])->toBe($asset->id);
        } finally {
            $restore();
        }
    });
});

describe('MediaTool::get_public_url', function (): void {
    it('mints a public_access_token when none exists', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $agentA, userId: 99, publicToken: null);
        $request = Request::create('https://spora.example/');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth(), null, $request);
        try {
            $result = $tool->execute(
                ['action' => 'get_public_url', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['asset_id'])->toBe($asset->id);
            expect($result->data['public_url'])->toStartWith(
                'https://spora.example/api/v1/public/media/' . $asset->id . '?token=',
            );

            $reloaded = MediaAsset::find($asset->id);
            expect($reloaded->public_access_token)->not->toBeNull()
                ->and($reloaded->public_access_token)->not->toBe('');
        } finally {
            $restore();
        }
    });

    it('returns the existing public_access_token when already set', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $agentA, userId: 99, publicToken: 'pre-existing-token');
        $request = Request::create('https://spora.example/');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth(), null, $request);
        try {
            $result = $tool->execute(
                ['action' => 'get_public_url', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->data['public_url'])->toContain('token=pre-existing-token');

            $reloaded = MediaAsset::find($asset->id);
            expect($reloaded->public_access_token)->toBe('pre-existing-token');
        } finally {
            $restore();
        }
    });

    it('returns 404 for an asset owned by another agent', function (): void {
        $ownerAgent = seedMediaToolAgent();
        $otherAgent = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $ownerAgent, userId: 99, publicToken: 'tok');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_public_url', 'asset_id' => $asset->id],
                agentId: $otherAgent,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('returns 404 when the asset does not exist', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_public_url', 'asset_id' => '99999999-9999-9999-9999-999999999999'],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('returns failure when asset_id is missing', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(['action' => 'get_public_url'], agentId: $agentA, userId: 99);

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('asset_id is required');
        } finally {
            $restore();
        }
    });

    it('falls back to HTTP_HOST when no request is provided', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $agentA, userId: 99, publicToken: 'fallback-token');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $previous = $_SERVER['HTTP_HOST'] ?? null;
            $_SERVER['HTTP_HOST'] = 'fallback.example';
            $result = $tool->execute(
                ['action' => 'get_public_url', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->data['public_url'])->toContain('fallback.example');

            if ($previous === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previous;
            }
        } finally {
            $restore();
        }
    });
});

describe('MediaTool::get_embed_code', function (): void {
    it('returns an <img> markdown snippet for image media', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(
            agentId: $agentA,
            userId: 99,
            mime: 'image/png',
            idOverride: 'aaaaaaaa-1111-2222-3333-444444444444',
        );

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toBe(
                '![sample.png](/api/v1/assets/aaaaaaaa-1111-2222-3333-444444444444.png)',
            );
            expect($result->data['embed'])->toBe($result->content);
            expect($result->data['asset_id'])->toBe($asset->id);
            expect($result->data['asset_url'])->toBe('/api/v1/assets/aaaaaaaa-1111-2222-3333-444444444444.png');
            expect($result->data['media_type'])->toBe('image');
        } finally {
            $restore();
        }
    });

    it('returns an <audio> snippet for audio media', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(
            agentId: $agentA,
            userId: 99,
            mime: 'audio/mpeg',
            idOverride: 'bbbbbbbb-1111-2222-3333-444444444444',
        );
        // Override media_type via direct DB update (the seed helper hardcodes 'image').
        Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $asset->id)
            ->update(['media_type' => 'audio']);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toBe(
                '<audio controls preload="metadata" src="/api/v1/assets/bbbbbbbb-1111-2222-3333-444444444444.mp3"></audio>',
            );
            expect($result->data['media_type'])->toBe('audio');
        } finally {
            $restore();
        }
    });

    it('returns a <video> snippet for video media with width/height', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(
            agentId: $agentA,
            userId: 99,
            mime: 'video/mp4',
            idOverride: 'cccccccc-1111-2222-3333-444444444444',
        );
        Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $asset->id)
            ->update([
                'media_type' => 'video',
                'width'      => 1280,
                'height'     => 720,
            ]);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toBe(
                '<video controls preload="metadata" playsinline width="1280" height="720" '
                . 'src="/api/v1/assets/cccccccc-1111-2222-3333-444444444444.mp4"></video>',
            );
            expect($result->data['media_type'])->toBe('video');
        } finally {
            $restore();
        }
    });

    it('returns a markdown link for document media', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(
            agentId: $agentA,
            userId: 99,
            mime: 'application/pdf',
            idOverride: 'dddddddd-1111-2222-3333-444444444444',
        );
        Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $asset->id)
            ->update(['media_type' => 'document']);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toBe(
                '[sample.png](/api/v1/assets/dddddddd-1111-2222-3333-444444444444.pdf)',
            );
            expect($result->data['media_type'])->toBe('document');
        } finally {
            $restore();
        }
    });

    it('returns a markdown link for unknown media_type', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(
            agentId: $agentA,
            userId: 99,
            mime: 'application/octet-stream',
            idOverride: 'eeeeeeee-1111-2222-3333-444444444444',
        );
        Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $asset->id)
            ->update(['media_type' => 'unknown']);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            // application/octet-stream has no extension map, so the URL has no suffix.
            expect($result->content)->toBe(
                '[sample.png](/api/v1/assets/eeeeeeee-1111-2222-3333-444444444444)',
            );
            expect($result->data['media_type'])->toBe('unknown');
        } finally {
            $restore();
        }
    });

    it('falls back to the asset_id when filename is null', function (): void {
        $agentA = seedMediaToolAgent();
        $asset = seedMediaAsset(
            agentId: $agentA,
            userId: 99,
            mime: 'application/pdf',
            idOverride: 'ffffffff-1111-2222-3333-444444444444',
        );
        Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $asset->id)
            ->update(['media_type' => 'document', 'filename' => null]);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => $asset->id],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeTrue();
            expect($result->content)->toBe(
                '[ffffffff-1111-2222-3333-444444444444](/api/v1/assets/ffffffff-1111-2222-3333-444444444444.pdf)',
            );
        } finally {
            $restore();
        }
    });

    it('returns 404 when asset_id is missing', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(['action' => 'get_embed_code'], agentId: $agentA, userId: 99);

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('asset_id is required');
        } finally {
            $restore();
        }
    });

    it('returns 404 when the asset does not exist', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => '99999999-9999-9999-9999-999999999999'],
                agentId: $agentA,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('returns 404 for an asset owned by another agent', function (): void {
        $ownerAgent = seedMediaToolAgent();
        $otherAgent = seedMediaToolAgent();
        $asset = seedMediaAsset(agentId: $ownerAgent, userId: 99);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_embed_code', 'asset_id' => $asset->id],
                agentId: $otherAgent,
                userId: 99,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });
});

describe('MediaTool routing', function (): void {
    it('rejects unknown action values', function (): void {
        $agentA = seedMediaToolAgent();

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(['action' => 'delete'], agentId: $agentA, userId: 99);

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Invalid action');
        } finally {
            $restore();
        }
    });

    it('describeAction formats search', function (): void {
        // describeAction does not touch the archive; a real archive instance
        // is the cheapest way to satisfy the constructor's non-nullable arg
        // given MediaArchiveService is `final` (Mockery can't fake final).
        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            expect($tool->describeAction(['action' => 'search']))->toBe('Media library search');
        } finally {
            $restore();
        }
    });

    it('describeAction formats get_media with asset id', function (): void {
        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            expect($tool->describeAction(['action' => 'get_media', 'asset_id' => 'abc']))
                ->toBe('Media get_media(abc)');
        } finally {
            $restore();
        }
    });

    it('describeAction formats get_public_url with asset id', function (): void {
        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            expect($tool->describeAction(['action' => 'get_public_url', 'asset_id' => 'xyz']))
                ->toBe('Media get_public_url(xyz)');
        } finally {
            $restore();
        }
    });
});
