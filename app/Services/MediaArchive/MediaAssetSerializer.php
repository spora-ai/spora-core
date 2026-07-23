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
 */
final class MediaAssetSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(MediaAsset $asset, ?string $host = null): array
    {
        // Scrub non-UTF-8 bytes (legacy Latin-1 filenames) so json_encode cannot choke.
        // See Spora\Services\Text\Utf8Sanitizer for the recovery algorithm.
        return Utf8Sanitizer::scrub([
            'id'                  => $asset->id,
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
            'public_url'          => $this->buildPublicUrl($asset, $host),
            'has_markdown'        => $asset->markdown_content !== null && $asset->markdown_content !== '',
            'created_at'          => $asset->created_at?->toIso8601String(),
            'updated_at'          => $asset->updated_at?->toIso8601String(),
        ]);
    }

    private function buildPublicUrl(MediaAsset $asset, ?string $host): ?string
    {
        // No token => no public URL. Without this guard the
        // MediaArchiveSharingTest PATCH-disable case leaks a URL
        // with a stray ?token= query string.
        if ($asset->public_access_token === null || $asset->public_access_token === '') {
            return null;
        }
        $base = $host !== null && $host !== ''
            ? rtrim($host, '/')
            : rtrim((string) ($_SERVER['HTTP_HOST'] ?? ''), '/');
        if ($base === '') {
            return null;
        }
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        return $scheme . '://' . $base . '/api/v1/public/media/' . $asset->id . '?token=' . $asset->public_access_token;
    }
}
