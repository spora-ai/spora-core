<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\Exceptions\NoDerivativeProducerException;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalService;
use Throwable;

/**
 * Owns the lifecycle of media derivatives.
 *
 * A derivative is a fresh `media_assets` row linked back to its parent
 * through the `media_derivatives` join table. The natural key on the
 * join — `(parent_id, format, producer_plugin, producer_operation)` —
 * makes the operation idempotent: re-rendering the same source with
 * the same producer overwrites the derivative's bytes rather than
 * stacking a new row.
 *
 * `principal_id` inheritance: the derivative inherits the parent's
 * `principal_id` when set; otherwise it pulls from the supplied
 * `PrincipalContext`, otherwise from
 * `PrincipalService::ensureUserPrincipal($userId)`, otherwise stays
 * NULL — matching the precedence chain used by the ingest pipeline so
 * LIST and CREATE agree on a row's "principal".
 *
 * `ensureTextDerivative()` is the automatic counterpart to the
 * operator-driven "Convert to" dropdown: the `md` extraction the LLM
 * reads for a binary document is a derivative, not a column, so ingest
 * and attach both mint one on the way in.
 *
 * Producer resolution: each registered
 * {@see MediaDerivativeProducerInterface} is instantiated through the
 * DI container rather than via `new $class()` — plugin producers
 * routinely take ctor arguments (e.g. `TypstRenderProducer` needs a
 * `TypstWorldFactory`), and only the container knows how to wire
 * them. The previous `new $class()` shape happened to work because
 * the core `ImageDerivativeProducer` has a no-arg ctor; it broke on
 * every plugin producer with dependencies, surfacing as
 * `ArgumentCountError` in `availableOptionsFor()` and
 * `MediaDerivativeController::findProducer()` whenever a `.typ` (or
 * any other plugin-driven) asset was opened.
 */
final class MediaDerivativeService
{
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * The one derivative format the LLM read path knows how to inline.
     * `AttachmentRowRenderer` and `MediaTool::get_source` both resolve
     * exactly this format, so it is a named contract rather than a
     * string literal repeated at each call site.
     */
    public const MARKDOWN_FORMAT = 'md';

    public function __construct(
        private readonly AssetStore $assetStore,
        private readonly PrincipalService $principalService,
        private readonly ContainerInterface $container,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Produce a derivative end-to-end: resolve the producer, call
     * `produce()`, persist the bytes + the `media_derivatives` join row
     * with attribution, and return the resulting `MediaAsset`.
     *
     * Single entry point for both {@see MediaDerivativeController} and
     * {@see \Spora\Tools\MediaTool::createDerivative} so producer
     * resolution lives in one place — adding a new producer or a new
     * attribution field is a single-site change.
     *
     * @param  array<string, mixed> $options
     *
     * @throws NoDerivativeProducerException when no registered producer
     *         advertises `$format` for the parent's MIME/extension.
     *         The HTTP controller maps this to 409 Conflict; the LLM
     *         tool maps it to a `ToolResult::fail()` so the assistant
     *         sees a human-readable explanation and can retry.
     * @throws Throwable any error from the producer's `produce()`
     *         propagates verbatim — the HTTP layer maps it to 422,
     *         the tool layer to `ToolResult::fail()`.
     */
    public function createFromRequest(
        MediaAsset $parent,
        string $format,
        array $options = [],
        ?int $userId = null,
        ?PrincipalContext $context = null,
    ): MediaAsset {
        $producer = $this->findProducer($parent, $format);
        if ($producer === null) {
            throw new NoDerivativeProducerException(sprintf(
                'No derivative producer supports format "%s" for asset %s (mime=%s, filename=%s).',
                $format,
                $parent->id,
                (string) ($parent->mime_type ?? ''),
                (string) ($parent->filename ?? ''),
            ));
        }
        $output = $producer->produce($parent, $format, $options);
        return $this->create(
            parent: $parent,
            output: $output,
            format: $format,
            producerPlugin: $producer->pluginSlug(),
            producerOperation: $producer->operationName(),
            userId: $userId,
            context: $context,
        );
    }

    /**
     * Remove every derivative of `$parent` — the derivative row, its
     * join row, and its on-disk payload — before the parent itself goes
     * away.
     *
     * This has to be explicit. `media_derivatives` carries foreign keys
     * on *both* columns with `cascadeOnDelete`, so deleting the parent
     * drops the join rows and leaves each derivative's own
     * `media_assets` row behind. {@see \Spora\Services\MediaArchive\MediaArchiveService::list()}
     * filters derivative rows out with
     * `whereNotIn('id', MediaDerivative::select('derivative_id'))` — so
     * the moment the join row is gone the orphan stops matching the
     * filter and resurfaces as a stray top-level library asset with no
     * route back to its source. In `local` mode its bytes stay on disk
     * as well.
     */
    public function deleteWithDerivatives(MediaAsset $parent): void
    {
        $derivativeIds = MediaDerivative::query()
            ->where('parent_id', $parent->id)
            ->pluck('derivative_id')
            ->all();

        if ($derivativeIds === []) {
            return;
        }

        foreach (MediaAsset::query()->whereIn('id', $derivativeIds)->get() as $derivative) {
            $this->unlinkStoredBytes($derivative);
            $derivative->delete();
        }

        // Explicit, not left to the FK cascade. Both cascades would
        // normally do this, but a cascade that silently does not fire
        // (SQLite without `PRAGMA foreign_keys=ON`, a partially applied
        // migration) is precisely what leaves the orphan row behind in
        // the first place. Deleting the join rows here makes the
        // guarantee ours rather than the engine's.
        MediaDerivative::query()
            ->where('parent_id', $parent->id)
            ->delete();
    }

    /**
     * Reverse lookup: given a derivative `media_assets` id, return the
     * parent asset id (or null when the asset isn't a derivative of
     * anything in the Spora archive). Mirrors the join shape used by
     * {@see create()}'s natural key — a derivative row has exactly one
     * parent row, when one exists.
     */
    public function parentOf(string $derivativeId): ?string
    {
        $parentId = MediaDerivative::query()
            ->where('derivative_id', $derivativeId)
            ->value('parent_id');
        return $parentId !== null ? (string) $parentId : null;
    }

    /**
     * The `md` derivative of `$parent`, if one exists. Read-only — the
     * counterpart of {@see ensureTextDerivative()}, for callers that
     * must not mint one (the LLM's read path; see
     * {@see \Spora\Agents\AttachmentRowRenderer}).
     */
    public function findTextDerivative(MediaAsset $parent): ?MediaAsset
    {
        $derivativeId = MediaDerivative::query()
            ->where('parent_id', $parent->id)
            ->where('format', self::MARKDOWN_FORMAT)
            ->orderBy('created_at', 'asc')
            ->value('derivative_id');

        if ($derivativeId === null) {
            return null;
        }
        return MediaAsset::query()->find((string) $derivativeId);
    }

    /**
     * Get-or-create the `md` derivative of `$parent`, the single shared
     * entry point for every automatic extraction: the ingest pipeline,
     * the attach-time seam in the task controllers, and (indirectly)
     * `get_source`.
     *
     * Best-effort by construction. Returns null — never throws — when
     * no registered producer accepts the parent (the common case: a
     * `text/*` source is its own text and needs no derivative) or when
     * the producer throws, so a corrupt PDF degrades to "the LLM gets a
     * `get_source` pointer" instead of failing the upload.
     *
     * Idempotent: a second call for the same parent returns the row the
     * first one created instead of re-running the producer, so re-ingest
     * and a blind retry are both safe.
     */
    public function ensureTextDerivative(MediaAsset $parent): ?MediaAsset
    {
        $existing = $this->findTextDerivative($parent);
        if ($existing !== null) {
            return $existing;
        }

        if ($this->findProducer($parent, self::MARKDOWN_FORMAT) === null) {
            return null;
        }

        try {
            $derivative = $this->createFromRequest(parent: $parent, format: self::MARKDOWN_FORMAT);
        } catch (Throwable $e) {
            $this->logger?->warning('MediaDerivativeService: text derivative failed', [
                'asset_id' => $parent->id,
                'mime'     => $parent->mime_type,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        // A producer that returns nothing (a scanned PDF with no text
        // layer) has nothing to store. Persisting an empty row would mean
        // every reader — the message builder, `get_source` — has to
        // special-case a derivative that carries no content, so decline
        // and let the caller fall through to its `get_source` pointer.
        if (trim($this->storedBytes($derivative)) === '') {
            $this->deleteWithDerivatives($parent);
            return null;
        }

        return $derivative;
    }

    /**
     * The derivative's stored bytes, read through the same
     * data-url/local split {@see self::rewriteStoredBytes()} writes with.
     */
    private function storedBytes(MediaAsset $derivative): string
    {
        if ($derivative->storage_mode === 'data_url') {
            return is_string($derivative->payload) ? $derivative->payload : '';
        }
        if ($derivative->storage_mode !== 'local') {
            return '';
        }
        $path = $this->localFilePath($derivative);
        if ($path === null) {
            return '';
        }
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $bytes = is_file($path) ? file_get_contents($path) : false;
        } finally {
            restore_error_handler();
        }
        return is_string($bytes) ? $bytes : '';
    }

    /**
     * Walk {@see MediaDerivativeProducerDiscovery::all()} and return the
     * first producer that accepts `$parent`'s MIME/extension and emits
     * `$format`. Mirrors the controller's `findProducer()` but lives
     * on the service so the controller and the tool both call the
     * same code path.
     */
    private function findProducer(MediaAsset $parent, string $format): ?MediaDerivativeProducerInterface
    {
        $format = strtolower($format);
        $mime   = strtolower((string) ($parent->mime_type ?? ''));
        $ext    = strtolower(pathinfo((string) ($parent->filename ?? ''), PATHINFO_EXTENSION));

        foreach (MediaDerivativeProducerDiscovery::all() as $class) {
            /** @var MediaDerivativeProducerInterface $producer */
            $producer = $this->container->get($class);
            $sources = array_map('strtolower', $producer->supportedSourceFormats());
            $outputs = array_map('strtolower', $producer->supportedDerivativeFormats());

            $sourceMatches = $mime !== '' && in_array($mime, $sources, true);
            if (!$sourceMatches && $ext !== '') {
                $sourceMatches = in_array($ext, $sources, true);
            }

            if (in_array($format, $outputs, true) && $sourceMatches) {
                return $producer;
            }
        }
        return null;
    }

    /**
     * Create or refresh a derivative. The bytes on the new `media_assets`
     * row are written via {@see AssetStore} (local-mode or data-url mode,
     * same as a regular upload).
     */
    public function create(
        MediaAsset $parent,
        DerivativeOutput $output,
        string $format,
        string $producerPlugin,
        string $producerOperation,
        ?int $userId = null,
        ?PrincipalContext $context = null,
    ): MediaAsset {
        $existing = $this->findExisting($parent, $format, $producerPlugin, $producerOperation);
        if ($existing !== null) {
            return $this->refresh($existing, $output);
        }
        return $this->createNew($parent, $output, $format, $producerPlugin, $producerOperation, $userId, $context);
    }

    /**
     * @return list<array{derivative: MediaAsset, format: string, producer_plugin: ?string, producer_operation: ?string, created_at: ?string}>
     */
    public function listFor(string $parentId): array
    {
        $rows = MediaDerivative::query()
            ->where('parent_id', $parentId)
            ->with('derivative')
            ->orderBy('created_at', 'asc')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $derivative = $row->derivative;
            if ($derivative === null) {
                continue;
            }
            $out[] = [
                'derivative'         => $derivative,
                'format'             => $row->format,
                'producer_plugin'    => $row->producer_plugin,
                'producer_operation' => $row->producer_operation,
                'created_at'         => $row->created_at?->format(self::DB_DATETIME_FORMAT),
            ];
        }
        return $out;
    }

    /**
     * Every source format the registered producers accept, lowercased,
     * MIMEs only. Backs
     * {@see \Spora\Services\MediaArchive\MediaAllowedTypesService}'s
     * upload allowlist: a binary document is uploadable precisely
     * because some producer can extract its text, so the producer
     * registry is the allowlist surface.
     *
     * Entries without a `/` are bare extensions (`md`, `typ`,
     * `markdown`) and are filtered out — `supportedSourceFormats()`
     * returns both shapes. The leak is invisible in the upload UI, which
     * maps MIME types through `extensionForMime()` and silently drops
     * what it cannot resolve, so it would surface only in the LLM-facing
     * "Allowed: %s" string as a bogus format name.
     *
     * `image/*` is excluded for a different reason: it would route
     * around the operator's image policy. Core's
     * {@see ImageDerivativeProducer} renders thumbnails, so its source
     * list is `image/png` and friends — unioning that in would make every
     * image type uploadable on every agent, defeating the
     * `supportsImageInput()` gate and the `allowed_image_types` config in
     * {@see MediaAllowedTypesService}. That list is the deliberate
     * surface for images; this one is for documents something can read.
     *
     * @return list<string>
     */
    public function producerSourceMimeTypes(): array
    {
        $mimes = [];
        foreach (MediaDerivativeProducerDiscovery::all() as $class) {
            /** @var MediaDerivativeProducerInterface $producer */
            $producer = $this->container->get($class);
            foreach ($producer->supportedSourceFormats() as $format) {
                $format = strtolower(trim($format));
                if (str_contains($format, '/') && !str_starts_with($format, 'image/')) {
                    $mimes[$format] = true;
                }
            }
        }
        return array_keys($mimes);
    }

    /**
     * Walk {@see MediaDerivativeProducerDiscovery::all()} and ask each
     * producer which derivative formats it can emit, marked with
     * `available` based on whether the producer's
     * `supportedSourceFormats()` contains the parent's MIME or
     * extension. Returns multiple candidates for UI dropdowns, one entry
     * per format across all producers.
     *
     * @return list<array{format: string, label: string, available: bool}>
     */
    public function availableOptionsFor(MediaAsset $parent): array
    {
        $mime = strtolower((string) $parent->mime_type);
        $ext  = strtolower(pathinfo((string) $parent->filename, PATHINFO_EXTENSION));
        $byFormat = [];
        foreach (MediaDerivativeProducerDiscovery::all() as $class) {
            /** @var MediaDerivativeProducerInterface $producer */
            $producer = $this->container->get($class);
            $sources = array_map('strtolower', $producer->supportedSourceFormats());
            $outputs = array_map('strtolower', $producer->supportedDerivativeFormats());
            $sourceMatches = $mime !== ''
                ? in_array($mime, $sources, true)
                : false;
            if (!$sourceMatches && $ext !== '') {
                $sourceMatches = in_array($ext, $sources, true);
            }
            foreach ($outputs as $format) {
                $key = $format;
                if (!isset($byFormat[$key])) {
                    $byFormat[$key] = [
                        'format'    => $format,
                        // Resolved per-producer below; the slug fallback
                        // keeps unknown formats presentable so producers
                        // shipped without an ImageDerivativeFormat-style
                        // catalogue still get a sensible label.
                        'label'     => ImageDerivativeFormat::labelFor($format),
                        'available' => false,
                    ];
                }
                if ($sourceMatches) {
                    $byFormat[$key]['available'] = true;
                }
            }
        }
        return array_values($byFormat);
    }

    private function findExisting(MediaAsset $parent, string $format, string $plugin, string $operation): ?MediaAsset
    {
        $derivativeId = MediaDerivative::query()
            ->where('parent_id', $parent->id)
            ->where('format', $format)
            ->where('producer_plugin', $plugin)
            ->where('producer_operation', $operation)
            ->value('derivative_id');

        return $derivativeId !== null ? MediaAsset::query()->find((string) $derivativeId) : null;
    }

    private function createNew(
        MediaAsset $parent,
        DerivativeOutput $output,
        string $format,
        string $producerPlugin,
        string $producerOperation,
        ?int $userId,
        ?PrincipalContext $context,
    ): MediaAsset {
        $reference = $this->assetStore->store($output->bytes, $output->mime, $this->filenameFor($parent, $format));

        $now = Carbon::now();
        $principalId = $parent->principal_id;
        if ($principalId === null && $context !== null && $context->principalId > 0) {
            $principalId = $context->principalId;
        }
        if ($principalId === null && $userId !== null) {
            // Stale or test-fixture user_ids won't have a `users` row.
            // `ensureUserPrincipal()` throws on missing users; swallow
            // and leave the derivative principal-less so the LIST
            // endpoint's back-compat agent-join still surfaces it.
            try {
                $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
            } catch (\Spora\Services\Exceptions\PrincipalMaterialisationException) {
                $principalId = null;
            }
        }

        $derivative = new MediaAsset();
        $derivative->id = self::generateUuid();
        $derivative->principal_id = $principalId !== null ? (int) $principalId : null;
        $derivative->agent_id = $parent->agent_id !== null ? (int) $parent->agent_id : null;
        $derivative->user_id = $userId ?? ($parent->user_id !== null ? (int) $parent->user_id : null);
        // A derivative is a child row in every sense the archive's
        // access and lifecycle queries care about, so ownership and the
        // temp flag are inherited and everything else is not:
        //
        //   user_id / agent_id — `AssetController::ownsDirectly()` and
        //     `MediaTool::assetInScope()` (scope=agent) are both hard
        //     gates; a NULL on either makes the row unreadable by the
        //     very agent that caused it to exist.
        //   is_temporary — `MediaArchiveRetention::findExcessTempIds()`
        //     filters `user_id + agent_id + is_temporary` together, so a
        //     derivative that missed any one of the three is immune to
        //     the sweep and grows without bound.
        //   task_id / tool_call_id — deliberately NOT inherited. The
        //     derivative outlives the turn, and a `tool_call_id` would
        //     collide with the ingest dedup key
        //     `(tool_call_id, source_url)`.
        //   tags / prompt — describe the source, not a render of it.
        //   public_access_token — must never be inherited: copying it
        //     would mint a second unauthenticated read path for a row
        //     nobody chose to share.
        $derivative->is_temporary = (bool) $parent->is_temporary;
        $derivative->plugin_slug = $producerPlugin;
        $derivative->tool_name = $producerOperation;
        $derivative->mime_type = $output->mime;
        $derivative->media_type = MediaType::fromMime($output->mime)->value;
        $derivative->byte_size = strlen($output->bytes);
        $derivative->width = $output->width;
        $derivative->height = $output->height;
        $derivative->duration_seconds = $output->durationSeconds;
        $derivative->storage_mode = $reference->mode;
        $derivative->asset_token = $reference->token ?? bin2hex(random_bytes(16));
        $derivative->upload_source = 'tool';
        $ext = MediaArchiveService::extensionForMime($output->mime);
        $derivative->asset_url = MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $derivative->id . ($ext !== null ? '.' . $ext : '');
        $derivative->filename = $this->filenameFor($parent, $format);
        $derivative->created_at = $now;
        $derivative->updated_at = $now;

        try {
            Capsule::connection()->transaction(function () use ($derivative, $output, $reference, $parent, $format, $producerPlugin, $producerOperation): void {
                $derivative->save();
                if ($reference->mode === 'data_url') {
                    $derivative->payload = $output->bytes;
                    $derivative->save();
                }
                (new MediaDerivative([
                    'id'                 => self::generateUuid(),
                    'parent_id'          => $parent->id,
                    'derivative_id'      => $derivative->id,
                    'format'             => $format,
                    'producer_plugin'    => $producerPlugin,
                    'producer_operation' => $producerOperation,
                    'created_at'         => date(self::DB_DATETIME_FORMAT),
                    'updated_at'         => date(self::DB_DATETIME_FORMAT),
                ]))->save();
            });
        } catch (Throwable $e) {
            $this->logger?->error('MediaDerivativeService: failed to insert derivative', [
                'parent_id' => $parent->id,
                'format'    => $format,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }

        return $derivative;
    }

    /**
     * Re-render in place on the existing derivative row.
     *
     * The bytes are rewritten, not just the metadata: `create_derivative`
     * documents idempotency on the natural key `(parent_id, format,
     * producer_plugin, producer_operation)`, so this is the path a
     * re-render takes — and for an `md` derivative, stale bytes are
     * silent text divergence, not a cosmetic issue. The LLM read paths
     * (chat attachment, `get_source`) both resolve the same row, so a
     * producer that fixes a bad extraction must be able to land it.
     */
    private function refresh(MediaAsset $existing, DerivativeOutput $output): MediaAsset
    {
        $existing->mime_type = $output->mime;
        $existing->media_type = MediaType::fromMime($output->mime)->value;
        $existing->byte_size = strlen($output->bytes);
        $existing->width = $output->width;
        $existing->height = $output->height;
        $existing->duration_seconds = $output->durationSeconds;
        $existing->updated_at = Carbon::now();
        $this->rewriteStoredBytes($existing, $output->bytes);
        $existing->save();
        return $existing;
    }

    /**
     * Overwrite the derivative's stored payload in place — the BLOB
     * column in `data_url` mode, the on-disk file in `local` mode. The
     * `asset_token` is deliberately left alone: a fresh token would
     * orphan the previous file on every re-render.
     */
    private function rewriteStoredBytes(MediaAsset $existing, string $bytes): void
    {
        if ($existing->storage_mode === 'data_url') {
            $existing->payload = $bytes;
            return;
        }
        if ($existing->storage_mode !== 'local') {
            return;
        }
        $path = $this->localFilePath($existing);
        if ($path === null) {
            return;
        }
        // PHP 8.4+ no longer fully honours `@` for file writes; the
        // explicit handler keeps a missing directory from surfacing as a
        // runtime warning the test suite flags as risky.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            file_put_contents($path, $bytes, LOCK_EX);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Absolute path of a `local`-mode asset's payload, or null when the
     * row carries no token to resolve one from. Mirrors
     * {@see \Spora\Services\LocalAssetStore::readFromAsset()}'s layout
     * (`<storage>/assets/<asset_token>.<ext>`).
     */
    private function localFilePath(MediaAsset $asset): ?string
    {
        $token = $asset->asset_token;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $ext = MediaArchiveService::extensionForMime($asset->mime_type);
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return (new \Spora\Core\Paths($basePath))->storage('assets')
            . '/' . $token . ($ext !== null ? '.' . $ext : '');
    }

    /**
     * Drop a `local`-mode derivative's payload from disk. A `data_url`
     * derivative's bytes live in the BLOB column the row delete removes
     * for us, and an `external` row has no Spora-side file at all.
     */
    private function unlinkStoredBytes(MediaAsset $asset): void
    {
        if ($asset->storage_mode !== 'local') {
            return;
        }
        $path = $this->localFilePath($asset);
        if ($path === null) {
            return;
        }
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            if (is_file($path)) {
                unlink($path);
            }
        } finally {
            restore_error_handler();
        }
    }

    private function filenameFor(MediaAsset $parent, string $format): string
    {
        $base = $parent->filename !== null && $parent->filename !== ''
            ? pathinfo($parent->filename, PATHINFO_FILENAME)
            : $parent->id;
        return $base . '.' . $format;
    }

    /**
     * Generate a UUIDv4 string without the `ramsey/uuid` dependency —
     * mirrors {@see MediaArchiveIngestPipeline::generateUuid()} so
     * derivative ids and ingest ids share the same canonical format.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
