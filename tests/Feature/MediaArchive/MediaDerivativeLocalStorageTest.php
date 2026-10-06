<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Carbon\Carbon;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\DerivativePayloadStore;
use Spora\Services\MediaArchive\MediaArchiveRetention;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Tests\Support\MediaArchiveTestSupport;

/**
 * `local`-mode derivative storage, end to end: a real
 * {@see LocalAssetStore} writes the bytes, a real
 * {@see DerivativePayloadStore} has to read them back.
 *
 * The whole file exists because the previous coverage only ever used
 * `text/markdown`. `md` is the one derivative format whose suffix is the
 * same under both candidate rules (`md` from the MIME, `md` from the
 * `.md` filename suffix), so a writer that named files one way and a
 * reader that looked for them another agreed on every fixture that
 * existed — while every real image derivative 404'd.
 *
 * Each test owns its tmp storage dir so parallel runs cannot delete each
 * other's bytes, and cleans up after itself so the `glob()` counts below
 * mean "this test's files", not "whatever else is in storage".
 */
beforeEach(function (): void {
    $dir = sys_get_temp_dir() . '/spora-deriv-local-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$dir}");
    $_ENV['SPORA_STORAGE_DIR']    = $dir;
    $_SERVER['SPORA_STORAGE_DIR'] = $dir;

    $GLOBALS['derivLocalDir'] = $dir;

    // Only the image producer: the natural key on a re-render is
    // (parent, format, plugin, operation), and the markdown producer
    // would answer for `md` rows these tests are not about.
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(
        \Spora\Services\MediaArchive\Producers\ImageDerivativeProducer::class,
    );
});

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();

    $dir = $GLOBALS['derivLocalDir'] ?? null;
    if (is_string($dir)) {
        array_map('unlink', glob($dir . '/assets/*') ?: []);
        @rmdir($dir . '/assets');
        @rmdir($dir);
        unset($GLOBALS['derivLocalDir']);
    }

    putenv('SPORA_STORAGE_DIR');
    unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
});

/**
 * An `auto` store with threshold 1, so every `store()` call lands in
 * `local` mode — the only mode with bytes on disk.
 */
function derivLocalStore(): AutoAssetStore
{
    return new AutoAssetStore(
        new DatabaseAssetStore(50 * 1024 * 1024),
        new LocalAssetStore(
            new Paths(BASE_PATH),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
        1,
    );
}

function derivLocalPayloads(): DerivativePayloadStore
{
    return new DerivativePayloadStore(BASE_PATH);
}

/**
 * A real PNG, so `ImageDerivativeProducer::produce()` can decode it. One
 * pixel of solid red, encoded inline so the fixture needs no fixture file.
 */
function derivLocalPngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );
}

/**
 * A `local`-mode parent with real PNG bytes on disk, written through the
 * same store the derivative will be written through.
 *
 * The row is hand-built rather than ingested because ingest would mint its
 * own `md` derivative and pull in the markdown producer, which has nothing
 * to do with the storage layout under test.
 */
function derivLocalParent(AutoAssetStore $store): MediaAsset
{
    $bytes = derivLocalPngBytes();
    $ref = $store->store($bytes, mime: 'image/png', filename: 'holiday.png');
    expect($ref->mode)->toBe('local');

    return MediaAsset::create([
        'id'           => testGenerateUuidV4(),
        'asset_url'    => '/api/v1/assets/' . testGenerateUuidV4() . '.png',
        'storage_mode' => 'local',
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
        'byte_size'    => strlen($bytes),
        'asset_token'  => $ref->token,
        'filename'     => 'holiday.png',
        'migrated_from_inline_data_url' => false,
    ]);
}

/**
 * The bytes every on-disk assertion in this file counts or reads.
 */
function derivLocalFiles(): array
{
    $dir = $GLOBALS['derivLocalDir'] ?? '';
    return glob($dir . '/assets/*') ?: [];
}

describe('local-mode derivative storage', function (): void {
    test('the exact regression: a thumbnail-256 derivative is readable from disk', function (): void {
        // The blocker, end to end. `MediaDerivativeService::filenameFor()`
        // names the row `holiday.thumbnail-256`, `LocalAssetStore::store()`
        // therefore writes `<token>.thumbnail-256`, and the old
        // `pathFor()` looked for `<token>.webp` — so every local-mode image
        // derivative 404'd, on the LLM read path and over HTTP alike.
        $store = derivLocalStore();
        $derivatives = MediaArchiveTestSupport::buildDerivativeService($store);
        $payloads = derivLocalPayloads();

        $parent = derivLocalParent($store);
        $derivative = $derivatives->createFromRequest($parent, 'thumbnail-256');
        $derivative = MediaAsset::query()->find((string) $derivative->id) ?? $derivative;

        expect($derivative->storage_mode)->toBe('local');
        expect($derivative->mime_type)->toBe('image/webp');
        expect((string) $derivative->filename)->toBe('holiday.thumbnail-256');

        // pathFor() must name the file the writer actually produced.
        $path = $payloads->pathFor($derivative);
        expect($path)->not->toBeNull();
        expect($path)->toEndWith($derivative->asset_token . '.thumbnail-256');
        expect(is_file($path))->toBeTrue();

        // And the reader must hand back the produced bytes, not an empty string.
        $bytes = $payloads->read($derivative);
        expect($bytes)->not->toBe('');
        expect(strlen($bytes))->toBe((int) $derivative->byte_size);

        // The store's own reader agrees, so `AssetController` can serve it.
        $reader = new LocalAssetStore(
            new Paths(BASE_PATH),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        );
        expect($reader->readFromAsset($derivative)['path'])->toBe($path);
    })->skip(!extension_loaded('gd') && !extension_loaded('imagick'), 'needs GD or Imagick');

    test('every image preset round-trips, so no format is special-cased into a 404', function (string $format, string $mime): void {
        $store = derivLocalStore();
        $derivatives = MediaArchiveTestSupport::buildDerivativeService($store);
        $payloads = derivLocalPayloads();

        $parent = derivLocalParent($store);
        $derivative = $derivatives->createFromRequest($parent, $format);
        $derivative = MediaAsset::query()->find((string) $derivative->id) ?? $derivative;

        expect($derivative->mime_type)->toBe($mime);
        expect($payloads->read($derivative))->not->toBe('');
        expect(is_file((string) $payloads->pathFor($derivative)))->toBeTrue();
    })->skip(!extension_loaded('gd') && !extension_loaded('imagick'), 'needs GD or Imagick')
        ->with([
            ['thumbnail-256', 'image/webp'],
            ['medium-1024', 'image/webp'],
            ['format-png', 'image/png'],
            ['format-jpeg', 'image/jpeg'],
            ['format-webp', 'image/webp'],
        ]);

    test('a re-render rewrites the same file rather than orphaning the previous one', function (): void {
        // `create_derivative` documents idempotency on the natural key, so a
        // repeat lands on `refresh()`. `refresh()` changes `mime_type`, and
        // the suffix the reader resolves used to be derived from the MIME —
        // so a refresh could resolve a different path, write a second file,
        // and leave the first orphaned on disk forever.
        $store = derivLocalStore();
        $derivatives = MediaArchiveTestSupport::buildDerivativeService($store);
        $payloads = derivLocalPayloads();

        $parent = derivLocalParent($store);
        $first = $derivatives->createFromRequest($parent, 'thumbnail-256');
        $first = MediaAsset::query()->find((string) $first->id) ?? $first;
        $pathBefore = $payloads->pathFor($first);
        expect($pathBefore)->not->toBeNull();
        $bytesBefore = $payloads->read($first);

        // Same natural key, different bytes and a different target MIME —
        // a producer that changed its mind between renders.
        $second = $derivatives->create(
            parent: $parent,
            output: new DerivativeOutput(
                bytes: 'RIFF0000WEBP-SECOND',
                mime: 'image/png',
                width: 1,
                height: 1,
                durationSeconds: null,
            ),
            format: 'thumbnail-256',
            producerPlugin: $first->plugin_slug,
            producerOperation: (string) $first->tool_name,
        );
        $second = MediaAsset::query()->find((string) $second->id) ?? $second;

        expect((string) $second->id)->toBe((string) $first->id);
        expect($payloads->pathFor($second))->toBe($pathBefore);
        expect($payloads->read($second))->toBe('RIFF0000WEBP-SECOND');
        expect($payloads->read($second))->not->toBe($bytesBefore);
        // Still two files: the parent's own bytes and the derivative's.
        // A third would be the orphaned original render, which no reader
        // would ever resolve again.
        expect(derivLocalFiles())->toHaveCount(2);
    })->skip(!extension_loaded('gd') && !extension_loaded('imagick'), 'needs GD or Imagick');

    test('deleting the parent unlinks the derivative bytes', function (): void {
        $store = derivLocalStore();
        $derivatives = MediaArchiveTestSupport::buildDerivativeService($store);
        $payloads = derivLocalPayloads();

        $parent = derivLocalParent($store);
        $derivative = $derivatives->createFromRequest($parent, 'thumbnail-256');
        $derivative = MediaAsset::query()->find((string) $derivative->id) ?? $derivative;
        $path = (string) $payloads->pathFor($derivative);
        expect(is_file($path))->toBeTrue();

        $derivatives->deleteWithDerivatives($parent);

        expect(MediaAsset::query()->find((string) $derivative->id))->toBeNull();
        expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
        expect(is_file($path))->toBeFalse();
    })->skip(!extension_loaded('gd') && !extension_loaded('imagick'), 'needs GD or Imagick');
});

describe('the temp sweep takes the derivative with it', function (): void {
    /**
     * An agent owned by a real user with a retention ceiling of 1, so the
     * very next temp row for that pair puts the set one over the limit and
     * the oldest one — the parent under test — gets swept.
     *
     * A real user id because `media_assets.user_id` and `agents.principal_id`
     * are FK-backed.
     */
    function derivLocalTempOwner(): array
    {
        static $counter = 0;
        $counter++;
        $userId = bootAuthLayer()->register(
            sprintf('deriv-local-%d-%s@example.com', $counter, bin2hex(random_bytes(4))),
            'Password1!',
            'Dl',
        );
        $config = \Spora\Models\LLMDriverConfiguration::create([
            'principal_id'  => null,
            'name'          => 'Derivative Local Config ' . bin2hex(random_bytes(3)),
            'driver_class'  => \Spora\Drivers\AnthropicCompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
        ]);
        $agentId = (int) \Spora\Models\Agent::create([
            'principal_id'                  => createUserPrincipalPublic($userId),
            'name'                          => 'Derivative Local Agent',
            'llm_driver_config_id'          => $config->id,
            'max_steps'                     => 10,
            'is_active'                     => true,
            'voice_message_retention_count' => 1,
        ])->id;

        return [$userId, $agentId];
    }

    test('the sweep removes a temp parent with no orphan derivative row and no orphan file', function (): void {
        // Derivatives inherit `is_temporary`, and ingest mints the `md`
        // derivative during the same upload `MediaUploadController` then
        // purges. A bulk `media_assets` delete drops the `media_derivatives`
        // join via the FK cascade but leaves the derivative's own row
        // behind — and once the join row is gone that orphan stops matching
        // `MediaArchiveService::list()`'s `whereNotIn` filter and resurfaces
        // as a stray top-level library asset, with its bytes still on disk.
        $store = derivLocalStore();
        $derivatives = MediaArchiveTestSupport::buildDerivativeService($store);
        $payloads = derivLocalPayloads();
        [$userId, $agentId] = derivLocalTempOwner();

        $parent = derivLocalParent($store);
        $parent->is_temporary = true;
        $parent->user_id = $userId;
        $parent->agent_id = $agentId;
        $parent->save();

        $derivative = $derivatives->createFromRequest($parent, 'thumbnail-256');
        $derivative = MediaAsset::query()->find((string) $derivative->id) ?? $derivative;

        // The derivative is swept in its own right — it inherited
        // `is_temporary` below — so `findExcessTempIds()` returns the parent
        // AND the derivative, ordered by a `created_at` that
        // `$table->timestamps()` only resolves to the second. The two tie,
        // and which row an engine hands back first is unspecified; SQLite
        // happens to return the parent, MySQL and MariaDB the derivative.
        // Backdate the derivative so the hardest order is the one under
        // test everywhere, rather than a test that only passes on whichever
        // engine breaks the tie the convenient way.
        $derivative->created_at = Carbon::now()->subMinute();
        $derivative->save();

        // The inheritance the sweep filters on: user + agent + is_temporary
        // together, so a derivative missing any one of the three is immune.
        expect((bool) $derivative->is_temporary)->toBeTrue();
        expect((int) $derivative->user_id)->toBe($userId);
        expect((int) $derivative->agent_id)->toBe($agentId);

        $derivativePath = (string) $payloads->pathFor($derivative);
        expect(is_file($derivativePath))->toBeTrue();

        // The parent's own bytes are on disk too, under the same store.
        $parentPath = (string) $payloads->pathFor($parent);
        expect(is_file($parentPath))->toBeTrue();

        // A newer non-derivative parent stands in for the upload the
        // controller just accepted; the sweep excludes it by id and
        // reaps the older parent instead.
        $fresh = derivLocalParent($store);
        $fresh->is_temporary = true;
        $fresh->user_id = $userId;
        $fresh->agent_id = $agentId;
        $fresh->save();

        $deleted = (new MediaArchiveRetention())->enforceTempRetention(
            $userId,
            $agentId,
            (string) $fresh->id,
        );

        expect($deleted)->toBeGreaterThanOrEqual(1);
        expect(MediaAsset::query()->find((string) $parent->id))->toBeNull();

        // The orphan row is gone...
        expect(MediaAsset::query()->find((string) $derivative->id))->toBeNull();
        expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);

        // ...and so are its bytes. A surviving file here is the storage
        // leak the sweep existed to prevent, not a cosmetic issue.
        expect(is_file($derivativePath))->toBeFalse();

        // The swept parent's own bytes go with it: `purgeTempRows()`
        // unlinks what it deletes, and the derivative cascade alone only
        // ever reached the children.
        expect(is_file($parentPath))->toBeFalse();

        // The newer sibling the sweep was told to keep really was kept —
        // and it kept its bytes too, so exactly one file is left standing.
        expect(MediaAsset::query()->find((string) $fresh->id))->not->toBeNull();
        expect(derivLocalFiles())->toHaveCount(1);
    })->skip(!extension_loaded('gd') && !extension_loaded('imagick'), 'needs GD or Imagick');
});
