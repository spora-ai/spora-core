<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\Producers\ImageDerivativeProducer;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Tests\Support\EmptyTextDerivativeProducer;
use Tests\Support\MediaArchiveTestSupport;
use Tests\Support\TextDerivativeProducer;
use Tests\Support\ThrowingTextDerivativeProducer;

/**
 * {@see MediaDerivativeService::ensureTextDerivative()} — the single
 * shared entry point every automatic `md` extraction goes through
 * (ingest, the attach-time seam, `get_source`).
 *
 * The contract under test is best-effort and idempotent: it must never
 * throw, never re-produce, and never mint a derivative for a source that
 * is already its own text.
 */
afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * A derivative service writing to a throwaway temp dir, with a producer
 * container that can materialise the registered producers.
 */
function ensureTextDerivativeService(?object $assetStore = null): MediaDerivativeService
{
    $store = $assetStore ?? MediaArchiveTestSupport::testAssetStore();
    $container = MediaArchiveTestSupport::buildProducerContainer();

    return new MediaDerivativeService(
        $store,
        new PrincipalService(new PrincipalResolver()),
        $container,
        null,
        new \Psr\Log\NullLogger(),
    );
}

/**
 * Register `$producer` as the last step before the call under test.
 *
 * The discovery list is process-global, so a sibling test in the same
 * parallel worker can reset it at any point. Registering inside the same
 * expression as the assertion under test is what keeps the test's
 * outcome independent of which worker it landed in.
 *
 * @param class-string<MediaDerivativeProducerInterface>|null $producer
 */
function registerEnsureTextProducer(?string $producer = TextDerivativeProducer::class): void
{
    // Reset first, then add. The registry is process-global and shared by
    // every test file the parallel runner packs into this worker, and
    // `findProducer()` returns the *first* registered producer that claims
    // the source and the format. A leftover sibling producer would
    // therefore win and silently become the producer under test — an empty
    // one makes `ensureTextDerivative()` return null and the failure looks
    // like the service is broken rather than like a registration race.
    MediaDerivativeProducerDiscovery::reset();
    if ($producer !== null) {
        MediaDerivativeProducerDiscovery::add($producer);
    }
}

function seedEnsureTextParent(
    string $mime = 'application/pdf',
    string $filename = 'doc.pdf',
    ?int $userId = null,
    ?int $agentId = null,
    bool $isTemporary = false,
): MediaAsset {
    $parent = MediaAsset::create([
        'id'                  => testGenerateUuidV4(),
        'asset_url'           => '/api/v1/assets/' . testGenerateUuidV4() . '.pdf',
        'storage_mode'        => 'data_url',
        'media_type'          => 'document',
        'mime_type'           => $mime,
        'byte_size'           => 2048,
        'user_id'             => $userId,
        'agent_id'            => $agentId,
        'is_temporary'        => $isTemporary,
        'asset_token'         => bin2hex(random_bytes(16)),
        'filename'            => $filename,
        'payload'             => '%PDF-1.4 body',
        'migrated_from_inline_data_url' => false,
    ]);

    // `media_assets` carries real FKs on `agent_id` / `user_id`, so the
    // ownership-inheritance tests need the referenced rows to exist. The
    // query builder rather than `update()` because Eloquent re-issues the
    // insert with stale attributes on a just-created model.
    MediaAsset::query()->where('id', $parent->id)->update([
        'user_id'      => $userId,
        'agent_id'     => $agentId,
        'is_temporary' => $isTemporary,
    ]);

    return MediaAsset::query()->find((string) $parent->id) ?? $parent;
}

test('creates the md derivative when none exists', function (): void {
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent();
    registerEnsureTextProducer();

    $derivative = $service->ensureTextDerivative($parent);

    expect($derivative)->not->toBeNull();
    expect($derivative->id)->not->toBe($parent->id);
    expect($derivative->mime_type)->toBe('text/markdown');
    expect($derivative->filename)->toBe('doc.md');
    // Attribution is what the column never had.
    expect($derivative->plugin_slug)->toBe('tests-text-derivative');
    expect($derivative->tool_name)->toBe('text.extract');

    $join = MediaDerivative::query()->where('parent_id', $parent->id)->first();
    expect($join)->not->toBeNull();
    expect($join->format)->toBe('md');
    expect($join->derivative_id)->toBe($derivative->id);
});

test('returns the existing derivative when one is already present', function (): void {
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent();
    registerEnsureTextProducer();
    $first = $service->ensureTextDerivative($parent);
    expect($first)->not->toBeNull();

    $second = $service->ensureTextDerivative($parent);

    expect($second)->not->toBeNull();
    expect($second->id)->toBe($first->id);
    // One row, not two.
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(1);
});

test('is idempotent across repeat calls — one row, one producer run', function (): void {
    // `produce()` on the double is not counted, so assert the
    // observable: repeated calls never add a second row.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent();
    registerEnsureTextProducer();

    $ids = [];
    for ($i = 0; $i < 4; $i++) {
        $derivative = $service->ensureTextDerivative($parent);
        expect($derivative)->not->toBeNull();
        $ids[] = $derivative->id;
    }

    expect(array_unique($ids))->toHaveCount(1);
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(1);
});

test('declines when no producer accepts the source', function (): void {
    // The image producer claims `image/png` → `thumbnail-256` and friends,
    // never `md`. So an image has no text derivative and the call
    // returns null rather than throwing or minting a bogus row.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent('image/png', 'pixel.png');
    registerEnsureTextProducer(ImageDerivativeProducer::class);

    expect($service->ensureTextDerivative($parent))->toBeNull();
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
});

test('declines when the registry holds only unrelated producers', function (): void {
    // The "nothing claims this source" branch, expressed as a source
    // nothing claims rather than as an empty registry — the registry is
    // process-global, so a sibling test in the same parallel worker can
    // populate it between a `reset()` and the call under test. An
    // unmatched MIME is deterministic in a way an empty list is not.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent('application/x-nothing-claims-this', 'thing.bin');
    registerEnsureTextProducer(ImageDerivativeProducer::class);

    expect($service->ensureTextDerivative($parent))->toBeNull();
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
});

test('swallows a producer throw and returns null', function (): void {
    // The corrupt-document contract: the upload survives, the extraction
    // degrades. A throw here would fail the user's upload.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent();
    registerEnsureTextProducer(ThrowingTextDerivativeProducer::class);

    expect($service->ensureTextDerivative($parent))->toBeNull();
    // And nothing half-written is left behind.
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
});

test('does not fire for an in-bounds text/markdown source', function (): void {
    // The half of the invariant that kills the duplicate. A PDF producer
    // is registered and is perfectly capable of running — it simply has
    // no reason to, because the source is already its own text.
    //
    // Deliberately NOT written as "a text/markdown asset never gets a
    // derivative": that phrasing would enshrine the regression where an
    // oversized `create_media` document lost its content. The claim here
    // is scoped to *in-bounds* text, which is what the raw-bytes inline
    // path serves.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent('text/markdown', 'notes.md');
    // In bounds: well under the 512 KB inline budget.
    $parent->byte_size = 1024;
    $parent->save();
    registerEnsureTextProducer();

    expect($service->ensureTextDerivative($parent))->toBeNull();
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
});

test('an out-of-bounds text/markdown source is still declined — the budget decides, not the mime', function (): void {
    // The other half of the same claim, and the reason the test above is
    // scoped the way it is: a 700 KB `create_media` document is out of
    // budget, so it needs the `get_source` round-trip — but it still must
    // not acquire a derivative that re-stores the same bytes. The content
    // stays readable through `get_source`; a duplicate row would be the
    // exact waste this change removes.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent('text/markdown', 'huge.md');
    $parent->byte_size = 700 * 1024;
    $parent->save();
    registerEnsureTextProducer();

    expect($service->ensureTextDerivative($parent))->toBeNull();
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
});

test('inherits user_id from the parent when no explicit user is given', function (): void {
    // Without this the derivative is unreadable by its own owner:
    // `AssetController::ownsDirectly()` is a hard gate on the
    // non-admin path.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent(userId: ensureTextRegisteredUserId());
    registerEnsureTextProducer();

    $derivative = $service->ensureTextDerivative($parent);

    expect($derivative)->not->toBeNull();
    expect((int) $derivative->user_id)->toBe((int) $parent->user_id);
    expect((int) $derivative->user_id)->toBeGreaterThan(0);
});

test('inherits agent_id from the parent', function (): void {
    // `MediaTool::assetInScope()` is `(int) $asset->agent_id === $agentId`
    // for `scope=agent`. A NULL agent_id makes the agent that created the
    // parent unable to read the extraction it caused.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent(agentId: ensureTextAgentId());
    registerEnsureTextProducer();

    $derivative = $service->ensureTextDerivative($parent);

    expect($derivative)->not->toBeNull();
    expect((int) $derivative->agent_id)->toBe((int) $parent->agent_id);
    expect((int) $derivative->agent_id)->toBeGreaterThan(0);
});

test('inherits is_temporary from the parent', function (): void {
    // `MediaArchiveRetention::findExcessTempIds()` filters
    // `user_id + agent_id + is_temporary` together, so a derivative that
    // missed any one of the three is immune to the sweep and grows
    // without bound.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent(isTemporary: true);
    registerEnsureTextProducer();

    $derivative = $service->ensureTextDerivative($parent);

    expect($derivative)->not->toBeNull();
    expect((bool) $derivative->is_temporary)->toBeTrue();
});

test('does not inherit public_access_token', function (): void {
    // Copying it would mint a second unauthenticated read path for a row
    // nobody chose to share. Spelled out as a test because "copy the
    // whole field list" is the natural wrong implementation.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent();
    $parent->public_access_token = 'parent-share-token';
    $parent->save();
    registerEnsureTextProducer();

    $derivative = $service->ensureTextDerivative($parent);

    expect($derivative)->not->toBeNull();
    expect($derivative->public_access_token)->toBeNull();
});

test('does not inherit task_id, tool_call_id, tags, or prompt', function (): void {
    // `tool_call_id` in particular would collide with the ingest dedup
    // key `(tool_call_id, source_url)`, and the other three describe the
    // source rather than a render of it.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent();
    // `task_id` / `tool_call_id` are FK-backed, so they need real rows.
    $ownerUserId = ensureTextRegisteredUserId();
    $ownerAgentId = ensureTextAgentId();
    $task = \Spora\Models\Task::create([
        'agent_id'        => $ownerAgentId,
        'principal_id'    => createUserPrincipalPublic($ownerUserId),
        'trigger_user_id' => $ownerUserId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'prompt',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);
    $toolCall = \Spora\Models\ToolCall::create([
        'task_id'             => $task->id,
        'agent_id'            => $ownerAgentId,
        'tool_name'           => 'test_tool',
        'tool_class'          => 'Spora\\Tools\\TestTool',
        'tool_type'           => 'builtin',
        'provider_call_id'    => 'call-' . bin2hex(random_bytes(4)),
        'status'              => 'PENDING',
        'proposed_arguments'  => '{}',
    ]);
    MediaAsset::query()->where('id', $parent->id)->update([
        'task_id'      => $task->id,
        'tool_call_id' => $toolCall->id,
        'tags'         => json_encode(['source-tag']),
        'prompt'       => 'the original brief',
    ]);
    $parent = MediaAsset::query()->find((string) $parent->id) ?? $parent;
    registerEnsureTextProducer();

    $derivative = $service->ensureTextDerivative($parent);

    expect($derivative)->not->toBeNull();
    expect($derivative->task_id)->toBeNull();
    expect($derivative->tool_call_id)->toBeNull();
    // And the parent really does carry them, so the nulls above are the
    // inheritance rules and not a fixture that never set them.
    expect($parent->task_id)->not->toBeNull();
    expect($parent->tool_call_id)->not->toBeNull();
    expect($parent->tags)->toBe(['source-tag']);
    expect($parent->prompt)->toBe('the original brief');
    expect($derivative->tags)->toBeNull();
    expect($derivative->prompt)->toBeNull();
});

test('a second md producer for the same source does not create a second row', function (): void {
    // The natural key is `(parent_id, format, producer_plugin,
    // producer_operation)`, so two different producers both emitting `md`
    // would normally produce two rows. `ensureTextDerivative()` returns
    // the existing one instead: a parent must have exactly one text, or
    // two readers can disagree about what the document says.
    $service = ensureTextDerivativeService();
    $parent = seedEnsureTextParent();
    registerEnsureTextProducer();
    $first = $service->ensureTextDerivative($parent);
    expect($first)->not->toBeNull();

    // Appended, not reset-then-added: the point of this case is that BOTH
    // producers are registered at once.
    MediaDerivativeProducerDiscovery::add(SecondTextDerivativeProducer::class);
    $second = $service->ensureTextDerivative($parent);

    expect($second)->not->toBeNull();
    expect($second->id)->toBe($first->id);
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(1);
});

test('producerSourceMimeTypes returns the producers\' MIME types', function (): void {
    $service = ensureTextDerivativeService();
    registerEnsureTextProducer();

    expect($service->producerSourceMimeTypes())->toBe(['application/pdf']);
});

test('producerSourceMimeTypes drops bare extensions', function (): void {
    // `supportedSourceFormats()` mixes MIMEs and extensions; a leaked
    // `md` would surface in the LLM-facing "Allowed: %s" string.
    $service = ensureTextDerivativeService();
    registerEnsureTextProducer(ExtensionMixingProducer::class);

    $mimes = $service->producerSourceMimeTypes();

    expect($mimes)->toContain('text/markdown');
    expect($mimes)->not->toContain('md');
    expect($mimes)->not->toContain('markdown');
});

test('producerSourceMimeTypes excludes image/* so the vision gate is not bypassed', function (): void {
    // Core's image producer's source list is `image/png` and friends.
    // Unioning it into the upload allowlist would make every image type
    // uploadable on every agent, defeating both `supportsImageInput()`
    // and the operator's `allowed_image_types` config.
    $service = ensureTextDerivativeService();
    registerEnsureTextProducer(ImageDerivativeProducer::class);

    $mimes = $service->producerSourceMimeTypes();

    expect($mimes)->not->toContain('image/png');
    expect($mimes)->not->toContain('image/jpeg');
    expect($mimes)->not->toContain('image/webp');
    expect($mimes)->not->toContain('image/gif');
});

/**
 * A real registered user id, since `media_assets.user_id` is FK-backed
 * and the ownership-inheritance test needs the reference to resolve.
 */
function ensureTextRegisteredUserId(): int
{
    return bootAuthLayer()->register(
        'deriv-owner-' . bin2hex(random_bytes(4)) . '@example.com',
        'Password1!',
        'Dv',
    );
}

/**
 * A real agent id, for the same FK reason as
 * {@see ensureTextRegisteredUserId()}.
 */
function ensureTextAgentId(): int
{
    $userId = ensureTextRegisteredUserId();
    $config = \Spora\Models\LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Derivative Scope Config',
        'driver_class' => \Spora\Drivers\AnthropicCompatibleDriver::class,
        'settings'     => json_encode(['api_key' => 'test']),
        'is_global'    => true,
        'is_default'   => true,
    ]);
    $agent = \Spora\Models\Agent::create([
        'principal_id'          => createUserPrincipalPublic($userId),
        'name'                  => 'Derivative Scope Agent',
        'llm_driver_config_id'  => $config->id,
        'max_steps'             => 10,
        'is_active'             => true,
    ]);
    return (int) $agent->id;
}

/**
 * A second producer claiming the same source and the same `md` output,
 * under its own attribution. Used to pin the one-text-per-parent rule.
 */
final class SecondTextDerivativeProducer implements MediaDerivativeProducerInterface
{
    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return ['application/pdf'];
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['md'];
    }

    public function pluginSlug(): string
    {
        return 'tests-second-derivative';
    }

    public function operationName(): string
    {
        return 'pdf.to_markdown';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        return new DerivativeOutput(bytes: 'a DIFFERENT extraction', mime: 'text/markdown');
    }
}

/**
 * Mirrors the shape real plugin producers have:
 * `supportedSourceFormats()` returning MIMEs *and* bare extensions.
 * The bare ones must not reach the upload allowlist.
 */
final class ExtensionMixingProducer implements MediaDerivativeProducerInterface
{
    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return ['text/markdown', 'md', 'markdown'];
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['docx'];
    }

    public function pluginSlug(): string
    {
        return 'tests-extension-mixing';
    }

    public function operationName(): string
    {
        return 'markdown.to_docx';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        return new DerivativeOutput(bytes: 'docx', mime: 'application/octet-stream');
    }
}

test('an empty extraction discards only its own derivative, not the parent\'s siblings', function (): void {
    $service = ensureTextDerivativeService();
    $parent  = seedEnsureTextParent();

    // A sibling derivative from an unrelated producer. The parent's `md`
    // extraction is about to come back empty (a scanned document), and
    // cleaning that up must not reach across and take this with it.
    $sibling = MediaAsset::create([
        'id'           => testGenerateUuidV4(),
        'asset_url'    => '/api/v1/assets/' . testGenerateUuidV4() . '.png',
        'storage_mode' => 'data_url',
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
        'byte_size'    => 128,
        'payload'      => 'PNG-bytes',
    ]);
    (new MediaDerivative([
        'id'                 => testGenerateUuidV4(),
        'parent_id'          => $parent->id,
        'derivative_id'      => $sibling->id,
        'format'             => 'thumbnail',
        'producer_plugin'    => 'some-other-plugin',
        'producer_operation' => 'render',
    ]))->save();

    // The `md` producer returns nothing, which reads as no content.
    registerEnsureTextProducer(EmptyTextDerivativeProducer::class);

    expect($service->ensureTextDerivative($parent))->toBeNull();

    // The empty `md` row is gone...
    expect(MediaDerivative::query()->where('parent_id', $parent->id)->where('format', 'md')->exists())->toBeFalse();
    expect(MediaAsset::query()->find((string) $sibling->id))->not->toBeNull();

    // ...and the sibling is untouched, join row and all.
    expect(MediaDerivative::query()->where('derivative_id', $sibling->id)->exists())->toBeTrue();
    expect((string) MediaAsset::query()->find((string) $sibling->id)?->payload)->toBe('PNG-bytes');
});
