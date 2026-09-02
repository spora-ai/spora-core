<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\ImageDerivativeFormat;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Tests\Support\FakeDerivativeProducer;

// `SPORA_STORAGE_DIR` is cleared at every test entry in `tests/Pest.php`
// (the parallel runner inherits whatever value the previous test left in
// the worker process). This fixture pushes its per-test tmp dir onto the
// process env so `Paths::storage()` resolves to an isolated root for the
// duration of the test; the Pest afterEach restores the snapshot taken
// at the previous test's entry.

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

function makeDerivativeServiceFixture(): array
{
    $tmp = sys_get_temp_dir() . '/spora-deriv-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $principalService = new PrincipalService(new PrincipalResolver());
    $service = new MediaDerivativeService($assetStore, $principalService);

    return ['service' => $service, 'tmp' => $tmp];
}

function seedDerivativeParent(?int $principalId = null): MediaAsset
{
    $id = sprintf(
        '%08x-aaaa-bbbb-cccc-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffffffffffff),
    );
    return MediaAsset::create([
        'id'                            => $id,
        'asset_url'                     => MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.png',
        'storage_mode'                  => 'data_url',
        'media_type'                    => 'image',
        'mime_type'                     => 'image/png',
        'byte_size'                     => 1024,
        'principal_id'                  => $principalId,
        'asset_token'                   => bin2hex(random_bytes(16)),
        'migrated_from_inline_data_url' => false,
    ]);
}

/**
 * Insert a `users` row so the principals.user_id FK is satisfied when
 * the test seeds a user-principal directly.
 */
function seedPrincipalRow(int $userId): void
{
    $pdo = Capsule::connection()->getPdo();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO users (id, email, password, username, verified, resettable, roles_mask, registered, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, 1, 1, 0, ?, ?, ?)',
    );
    $email = sprintf('derivative-test-%d@example.com', $userId);
    $now = time();
    $stmt->execute([
        $userId,
        $email,
        password_hash('Password1!', PASSWORD_BCRYPT),
        $email,
        $now,
        date('Y-m-d H:i:s', $now),
        date('Y-m-d H:i:s', $now),
    ]);
}

test('MediaDerivativeService::create writes a fresh media_assets row + media_derivatives link', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $derivative = $service->create(
        parent: $parent,
        output: new DerivativeOutput('%PDF-1', 'application/pdf'),
        format: 'pdf',
        producerPlugin: 'fake-derivative-producer',
        producerOperation: 'render',
    );

    expect($derivative->mime_type)->toBe('application/pdf');
    expect($derivative->principal_id)->toBe($parent->principal_id);

    $rows = Capsule::table('media_derivatives')->get();
    expect($rows)->toHaveCount(1);
    expect((string) $rows[0]->parent_id)->toBe($parent->id);
    expect((string) $rows[0]->derivative_id)->toBe($derivative->id);
    expect((string) $rows[0]->format)->toBe('pdf');
    expect((string) $rows[0]->producer_plugin)->toBe('fake-derivative-producer');
    expect((string) $rows[0]->producer_operation)->toBe('render');
});

test('MediaDerivativeService::create refreshes the existing derivative on a re-render (no duplicate)', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $first  = $service->create($parent, new DerivativeOutput('v1', 'application/pdf'), 'pdf', 'p', 'op');
    $second = $service->create($parent, new DerivativeOutput('v2', 'application/pdf'), 'pdf', 'p', 'op');

    expect($second->id)->toBe($first->id);
    expect((int) $second->byte_size)->toBe(2);
    // Single media_derivatives row.
    expect(Capsule::table('media_derivatives')->count())->toBe(1);
});

test('different (format, plugin, operation) triples create distinct derivatives', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $pdf  = $service->create($parent, new DerivativeOutput('pdf', 'application/pdf'), 'pdf', 'p', 'op');
    $png  = $service->create($parent, new DerivativeOutput('png', 'image/png'), 'png', 'p', 'op');
    $svg  = $service->create($parent, new DerivativeOutput('svg', 'image/svg+xml'), 'svg', 'p', 'op');

    expect($pdf->id)->not->toBe($png->id);
    expect($png->id)->not->toBe($svg->id);
    expect(Capsule::table('media_derivatives')->count())->toBe(3);
});

test('deleting the parent cascades to the media_derivatives link row', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $service->create($parent, new DerivativeOutput('pdf', 'application/pdf'), 'pdf', 'p', 'op');
    expect(Capsule::table('media_derivatives')->count())->toBe(1);

    $parent->delete();
    expect(Capsule::table('media_derivatives')->count())->toBe(0);
});

test('derivative inherits principal_id from the parent when set', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    seedPrincipalRow(99999);
    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type' => 'user', 'user_id' => 99999,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $parent = seedDerivativeParent($principalId);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $derivative = $service->create($parent, new DerivativeOutput('pdf', 'application/pdf'), 'pdf', 'p', 'op');
    expect((int) $derivative->principal_id)->toBe($principalId);
});

test('derivative falls back to PrincipalContext when the parent has no principal_id', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent(null);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    seedPrincipalRow(99998);
    $contextPrincipalId = (int) Capsule::table('principals')->insertGetId([
        'type' => 'user', 'user_id' => 99998,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $context = new PrincipalContext(
        principalId: $contextPrincipalId,
        type: Principal::TYPE_USER,
        ownerUserId: 99998,
        runnerUserId: 99998,
    );

    $derivative = $service->create(
        parent: $parent,
        output: new DerivativeOutput('pdf', 'application/pdf'),
        format: 'pdf',
        producerPlugin: 'p',
        producerOperation: 'op',
        context: $context,
    );
    expect((int) $derivative->principal_id)->toBe($contextPrincipalId);
});

test('derivative stays principal_id NULL when parent has none, no context, no userId', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent(null);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $derivative = $service->create(
        parent: $parent,
        output: new DerivativeOutput('pdf', 'application/pdf'),
        format: 'pdf',
        producerPlugin: 'p',
        producerOperation: 'op',
    );
    expect($derivative->principal_id)->toBeNull();
});

test('MediaDerivativeService::availableOptionsFor marks supported formats as available', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $options = $service->availableOptionsFor($parent);
    $byFormat = [];
    foreach ($options as $opt) {
        $byFormat[$opt['format']] = $opt['available'];
    }
    // The default FakeDerivativeProducer advertises image/png → pdf,
    // so the only candidate format is `pdf` and it's available.
    expect($byFormat)->toBe(['pdf' => true]);
});

test('producing a derivative with no registered producers returns an empty options array', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();

    $options = $service->availableOptionsFor($parent);
    expect($options)->toBe([]);
});

test('MediaDerivativeService::listFor returns the derivative rows for a parent', function (): void {
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $derivative = $service->create($parent, new DerivativeOutput('pdf', 'application/pdf'), 'pdf', 'p', 'op');

    $rows = $service->listFor($parent->id);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['format'])->toBe('pdf');
    expect($rows[0]['producer_plugin'])->toBe('p');
    expect($rows[0]['producer_operation'])->toBe('op');
    expect($rows[0]['derivative']->id)->toBe($derivative->id);
});

test('ImageDerivativeFormat::chipLabelFor maps each preset to a chip-friendly string', function (): void {
    // The VersionsStrip chip row mirrors the dropdown options but
    // needs shorter labels (the dropdown gets "Thumbnail (256px)";
    // the chip is constrained to ~7-8 chars). Keep this list in sync
    // with FORMAT_PRESETS — adding a preset without a chip label
    // would surface an unreadable slug.
    expect(ImageDerivativeFormat::chipLabelFor('thumbnail-256'))->toBe('Thumb 256');
    expect(ImageDerivativeFormat::chipLabelFor('medium-1024'))->toBe('Medium 1024');
    expect(ImageDerivativeFormat::chipLabelFor('format-png'))->toBe('PNG');
    expect(ImageDerivativeFormat::chipLabelFor('format-jpeg'))->toBe('JPEG');
    expect(ImageDerivativeFormat::chipLabelFor('format-webp'))->toBe('WebP');
    // Future producers outside the catalogue fall back to the slug.
    expect(ImageDerivativeFormat::chipLabelFor('avif'))->toBe('AVIF');
});

test('MediaAssetSerializer::serialize attaches a chip label per derivative on the wire', function (): void {
    // The VersionsStrip chip row reads `derivative.label` from the
    // serializer payload; without it the chip would show the raw
    // preset key (`FORMAT-PNG`) instead of a short identifier (`PNG`).
    ['service' => $service] = makeDerivativeServiceFixture();
    $parent = seedDerivativeParent();
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    // Three derivatives from two producers — the in-core producer gets
    // chip labels via ImageDerivativeFormat; the FakeDerivativeProducer
    // falls back to the upper-case format slug because it's outside
    // the catalogue.
    $service->create(
        parent: $parent,
        output: new DerivativeOutput('png', 'image/png'),
        format: 'format-png',
        producerPlugin: 'spora-core',
        producerOperation: 'image.derive',
    );
    $service->create(
        parent: $parent,
        output: new DerivativeOutput('thumb', 'image/webp'),
        format: 'thumbnail-256',
        producerPlugin: 'spora-core',
        producerOperation: 'image.derive',
    );
    $service->create(
        parent: $parent,
        output: new DerivativeOutput('pdf', 'application/pdf'),
        format: 'pdf',
        producerPlugin: 'fake-derivative-producer',
        producerOperation: 'render',
    );

    $serializer = new MediaAssetSerializer(includeDerivatives: true, derivatives: $service);
    $payload    = $serializer->serialize($parent);

    $byFormat = [];
    foreach ($payload['derivatives'] as $row) {
        $byFormat[$row['format']] = $row;
    }
    expect($byFormat['format-png']['label'])->toBe('PNG');
    expect($byFormat['thumbnail-256']['label'])->toBe('Thumb 256');
    expect($byFormat['pdf']['label'])->toBe('PDF');
});
