<?php

declare(strict_types=1);

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Models\User;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaAssetReader;

/**
 * Coverage for {@see MediaAssetReader} — the in-process byte-read
 * entry point that the plugin layer uses to forward Media Archive
 * assets to downstream APIs (e.g. video generation) without going
 * through the HTTP layer.
 */

/**
 * Boot a fresh temp storage dir + Local/Database stub pair so the
 * reader's storage_mode dispatch can read real bytes. Mirrors the
 * shape of `makeMediaArchiveService()` from CrossFileTestHelpers
 * but only constructs what the reader needs.
 *
 * @return array{reader: MediaAssetReader, database: DatabaseAssetStore, local: LocalAssetStore, tmp: string, restore: callable}
 */
function makeMediaAssetReader(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-reader-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore();
    $local    = new LocalAssetStore($paths, $security);

    $restore = static function () use ($tmp): void {
        putenv('SPORA_STORAGE_DIR');
        unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        if (is_dir($tmp)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($tmp);
        }
    };

    return [
        'reader'   => new MediaAssetReader($database, $local),
        'database' => $database,
        'local'    => $local,
        'tmp'      => $tmp,
        'restore'  => $restore,
    ];
}

/**
 * Persist a MediaAsset row with the given attributes. The test
 * database is in-memory SQLite per-test (rolled back at teardown
 * by the global `beforeEach` / `afterEach` in tests/Pest.php), so
 * explicit cleanup is unnecessary.
 */
function persistMediaAsset(array $attrs): MediaAsset
{
    $asset = new MediaAsset();
    // CHAR(36) primary key — the service generates one at insert time
    // (see MediaArchiveService::generateUuid()), so tests must mint
    // their own before save() or the row never lands.
    if (!isset($attrs['id'])) {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $attrs['id'] = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
    // `asset_url` is NOT NULL — the service writes the opaque
    // `/api/v1/assets/<uuid>` form on insert, so tests that bypass
    // ingest must supply a placeholder.
    $attrs['asset_url'] ??= '/api/v1/assets/' . $attrs['id'];
    $asset->setRawAttributes($attrs, true);
    $asset->save();
    return $asset;
}

function persistAgent(int $userId): Agent
{
    // `agents.user_id` has a foreign key to `users.id`, so we need a
    // matching user row before the agent can persist. The fields are
    // a minimal valid subset — the FK is the only contract tested here.
    $user = new User();
    $user->id = $userId;
    $user->email = "user{$userId}@example.test";
    $user->password = str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $user->username = "user{$userId}";
    $user->status = 0;
    $user->verified = 1;
    $user->resettable = 1;
    $user->roles_mask = 0;
    $user->registered = time();
    $user->save();

    $agent = new Agent();
    $agent->user_id = $userId;
    $agent->name = 'test-agent';
    $agent->is_active = true;
    $agent->save();
    return $agent;
}

// ----- Empty / missing ---------------------------------------------------

describe('MediaAssetReader::readAsset', function (): void {
    it('returns null when the UUID does not exist', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $missing = '00000000-0000-0000-0000-000000000000';
            expect($ctx['reader']->readAsset($missing, 1))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    // ----- data_url mode ------------------------------------------------

    it('returns the data_url payload for an owned asset', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => 'pixel-bytes',
                'byte_size'    => 10,
            ]);
            $result = $ctx['reader']->readAsset($asset->id, 7);
            expect($result)->toBe([
                'status' => 'data_url',
                'bytes'  => 'pixel-bytes',
                'mime'   => 'image/png',
            ]);
        } finally {
            $ctx['restore']();
        }
    });

    it('returns null for a data_url row whose payload is missing', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => null,
            ]);
            expect($ctx['reader']->readAsset($asset->id, 7))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    // ----- local mode ---------------------------------------------------

    it('returns the local mode payload from disk', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $reference = $ctx['local']->store('local-payload', 'image/png', 'pixel.png');
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/png',
                'storage_mode' => 'local',
                'asset_token'  => $reference->token,
                'byte_size'    => 12,
            ]);
            $result = $ctx['reader']->readAsset($asset->id, 7);
            expect($result)->toBe([
                'status' => 'local',
                'bytes'  => 'local-payload',
                'mime'   => 'image/png',
            ]);
        } finally {
            $ctx['restore']();
        }
    });

    it('returns null for a local row whose file is missing on disk', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/png',
                'storage_mode' => 'local',
                'asset_token'  => bin2hex(random_bytes(16)),
            ]);
            expect($ctx['reader']->readAsset($asset->id, 7))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    // ----- external mode -----------------------------------------------

    it('returns the external source URL', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/jpeg',
                'storage_mode' => 'external',
                'source_url'   => 'https://cdn.example.com/asset.jpg',
            ]);
            $result = $ctx['reader']->readAsset($asset->id, 7);
            expect($result)->toBe([
                'status'    => 'external',
                'sourceUrl' => 'https://cdn.example.com/asset.jpg',
            ]);
        } finally {
            $ctx['restore']();
        }
    });

    it('returns null for an external row without a source_url', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/jpeg',
                'storage_mode' => 'external',
                'source_url'   => null,
            ]);
            expect($ctx['reader']->readAsset($asset->id, 7))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    // ----- Unknown storage_mode ---------------------------------------

    it('returns null for an unknown storage_mode', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/png',
                'storage_mode' => 'legacy_inline_blob',
            ]);
            expect($ctx['reader']->readAsset($asset->id, 7))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    // ----- Ownership ---------------------------------------------------

    it('returns null when userId is provided but does not match the owner', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => 'pixel',
            ]);
            expect($ctx['reader']->readAsset($asset->id, 8))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    it('returns the payload when the caller owns the asset via the producing agent', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $agent = persistAgent(7);
            $asset = persistMediaAsset([
                'user_id'      => null,
                'agent_id'     => $agent->id,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => 'agent-produced',
            ]);
            $result = $ctx['reader']->readAsset($asset->id, 7);
            expect($result)->toBe([
                'status' => 'data_url',
                'bytes'  => 'agent-produced',
                'mime'   => 'image/png',
            ]);
        } finally {
            $ctx['restore']();
        }
    });

    it('returns null when neither the asset nor the agent belong to userId', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $agent = persistAgent(7);
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'agent_id'     => $agent->id,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => 'mine',
            ]);
            expect($ctx['reader']->readAsset($asset->id, 8))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    it('returns null when userId is provided but the asset has no user_id and no agent', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => null,
                'agent_id'     => null,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => 'orphan',
            ]);
            expect($ctx['reader']->readAsset($asset->id, 1))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    it('returns null when the producing agent has been deleted', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $agent = persistAgent(7);
            $asset = persistMediaAsset([
                'user_id'      => null,
                'agent_id'     => $agent->id,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => 'orphan-agent',
            ]);
            $agent->delete();
            expect($ctx['reader']->readAsset($asset->id, 7))->toBeNull();
        } finally {
            $ctx['restore']();
        }
    });

    it('bypasses the ownership check when userId is null (system context)', function (): void {
        $ctx = makeMediaAssetReader();
        try {
            $asset = persistMediaAsset([
                'user_id'      => 7,
                'mime_type'    => 'image/png',
                'storage_mode' => 'data_url',
                'payload'      => 'system-readable',
            ]);
            $result = $ctx['reader']->readAsset($asset->id, null);
            expect($result)->toBe([
                'status' => 'data_url',
                'bytes'  => 'system-readable',
                'mime'   => 'image/png',
            ]);
        } finally {
            $ctx['restore']();
        }
    });
});
