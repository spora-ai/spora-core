<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\MediaAsset;
use Spora\Services\Text\Utf8Sanitizer;

/**
 * Single source of truth for the wire shape of a MediaAsset.
 *
 * Both MediaArchiveController and MediaUploadController need to
 * return the same payload - extracting the serializer here removes
 * a cross-controller static call and makes it trivially testable.
 *
 * The `derivatives` field is opt-in via the `$includeDerivatives`
 * constructor flag (default true). LIST loops should pass `false`
 * to avoid the N+1 — the detail page (single asset) is the natural
 * consumer that always includes them.
 */
final class MediaAssetSerializer
{
    public function __construct(
        private readonly bool $includeDerivatives = true,
        private readonly ?MediaDerivativeService $derivatives = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(MediaAsset $asset, ?string $baseUrl = null): array
    {
        // Scrub non-UTF-8 bytes (legacy Latin-1 filenames) so json_encode cannot choke.
        // See Spora\Services\Text\Utf8Sanitizer for the recovery algorithm.
        return Utf8Sanitizer::scrub([
            'id'                  => $asset->id,
            'principal_id'        => $asset->principal_id,
            'agent_id'            => $asset->agent_id,
            'task_id'             => $asset->task_id,
            'tool_call_id'        => $asset->tool_call_id,
            'user_id'             => $asset->user_id,
            'plugin_slug'         => $asset->plugin_slug,
            'tool_name'           => $asset->tool_name,
            'media_type'          => $asset->media_type,
            'mime_type'           => $asset->mime_type,
            'byte_size'           => $asset->byte_size,
            'width'               => $asset->width,
            'height'              => $asset->height,
            'duration_seconds'    => $asset->duration_seconds,
            'prompt'              => $asset->prompt,
            'filename'            => $asset->filename,
            'markdown_content'    => $asset->markdown_content,
            'tags'                => $asset->tags,
            'metadata'            => $asset->metadata,
            'asset_url'           => $asset->publicUrl(),
            'source_url'          => $asset->source_url,
            'storage_mode'        => $asset->storage_mode,
            'upload_source'       => $asset->upload_source,
            'public_access_token' => $asset->public_access_token,
            'public_url'          => $this->buildPublicUrl($asset, $baseUrl),
            'has_markdown'        => $asset->markdown_content !== null && $asset->markdown_content !== '',
            'derivatives'         => $this->loadDerivatives($asset),
            'created_at'          => $asset->created_at?->toIso8601String(),
            'updated_at'          => $asset->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @param string|null $baseUrl Absolute base URL (with scheme + host) the
     *        caller wants the share link to use. Controllers pass the
     *        resolved `config.app_url` (configured via `config.php` /
     *        `SPORA_APP_URL`, or detected by `RequestOrigin::detect()` at
     *        boot). When null/empty, the public URL is omitted from the
     *        payload — share URLs are off when the operator has not
     *        configured a public origin.
     */
    private function buildPublicUrl(MediaAsset $asset, ?string $baseUrl): ?string
    {
        // No token => no public URL. Without this guard the
        // MediaArchiveSharingTest PATCH-disable case leaks a URL
        // with a stray ?token= query string.
        if ($asset->public_access_token === null || $asset->public_access_token === '') {
            return null;
        }
        if ($baseUrl === null || $baseUrl === '') {
            return null;
        }
        return rtrim($baseUrl, '/') . '/api/v1/public/media/' . $asset->id . '?token=' . $asset->public_access_token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDerivatives(MediaAsset $asset): array
    {
        if (!$this->includeDerivatives || $this->derivatives === null) {
            return [];
        }
        $rows = $this->derivatives->listFor($asset->id);
        $out = [];
        foreach ($rows as $row) {
            $derivative = $row['derivative'];
            $ext = MediaArchiveService::extensionForMime($derivative->mime_type);
            // Per-derivative chip label for the VersionsStrip. Producers
            // ship their own label resolution through
            // `MediaDerivativeFormat::chipLabelFor()` so adding a new
            // preset is a one-row change in the catalogue.
            $label = $row['producer_plugin'] === 'spora-core'
                ? ImageDerivativeFormat::chipLabelFor($row['format'])
                : strtoupper($row['format']);
            $out[] = [
                'format'             => $row['format'],
                'label'              => $label,
                'media_id'           => $derivative->id,
                'asset_url'          => MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $derivative->id . ($ext !== null ? '.' . $ext : ''),
                'producer_plugin'    => $row['producer_plugin'],
                'producer_operation' => $row['producer_operation'],
                'created_at'         => $row['created_at'],
            ];
        }
        return $out;
    }
}
