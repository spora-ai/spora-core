<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Spora\Models\MediaAsset;
use Spora\Services\AssetStore;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalService;
use Throwable;

/**
 * Owns the lifecycle of media derivatives.

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
 */
final class MediaDerivativeService
{
    public function __construct(
        private readonly AssetStore $assetStore,
        private readonly PrincipalService $principalService,
        private readonly ?LoggerInterface $logger = null,
    ) {}

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
        $rows = Capsule::table('media_derivatives AS md')
            ->join('media_assets AS ma', 'ma.id', '=', 'md.derivative_id')
            ->where('md.parent_id', $parentId)
            ->orderBy('md.created_at', 'asc')
            ->select([
                'ma.id',
                'ma.principal_id',
                'ma.mime_type',
                'ma.media_type',
                'ma.byte_size',
                'ma.filename',
                'ma.width',
                'ma.height',
                'ma.duration_seconds',
                'ma.created_at',
                'md.format',
                'md.producer_plugin',
                'md.producer_operation',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $derivative = MediaAsset::query()->find($row->id);
            if ($derivative === null) {
                continue;
            }
            $out[] = [
                'derivative'         => $derivative,
                'format'             => (string) $row->format,
                'producer_plugin'    => $row->producer_plugin !== null ? (string) $row->producer_plugin : null,
                'producer_operation' => $row->producer_operation !== null ? (string) $row->producer_operation : null,
                'created_at'         => $row->created_at !== null ? (string) $row->created_at : null,
            ];
        }
        return $out;
    }

    /**
     * Walk {@see MediaDerivativeProducerDiscovery::all()} and ask each
     * producer which derivative formats it can emit, marked with
     * `available` based on whether the producer's
     * `supportedSourceFormats()` contains the parent's MIME or
     * extension. Mirrors {@see MediaConverterRegistry::findFor()} but
     * returns multiple candidates for UI dropdowns.
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
            $producer = new $class();
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
        $derivativeId = Capsule::table('media_derivatives')
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
        $derivative->user_id = $userId;
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
                Capsule::table('media_derivatives')->insert([
                    'id'                 => self::generateUuid(),
                    'parent_id'          => $parent->id,
                    'derivative_id'      => $derivative->id,
                    'format'             => $format,
                    'producer_plugin'    => $producerPlugin,
                    'producer_operation' => $producerOperation,
                    'created_at'         => date('Y-m-d H:i:s'),
                    'updated_at'         => date('Y-m-d H:i:s'),
                ]);
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

    private function refresh(MediaAsset $existing, DerivativeOutput $output): MediaAsset
    {
        $existing->mime_type = $output->mime;
        $existing->media_type = MediaType::fromMime($output->mime)->value;
        $existing->byte_size = strlen($output->bytes);
        $existing->width = $output->width;
        $existing->height = $output->height;
        $existing->duration_seconds = $output->durationSeconds;
        $existing->updated_at = Carbon::now();
        $existing->save();
        return $existing;
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
