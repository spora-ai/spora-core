<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\MediaAsset;

/**
 * Single source of truth for the wire shape of a {@see MediaAsset}.
 *
 * Both {@see \Spora\Http\MediaArchiveController} and
 * {@see \Spora\Http\MediaUploadController} need to return the same
 * payload — extracting the serializer here removes a cross-controller
 * static call and makes it trivially testable.
 */
final class MediaAssetSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(MediaAsset $asset, ?string $host = null): array
    {
        // Scrub non-UTF-8 bytes (legacy Latin-1 filenames) so json_encode cannot choke.
        return self::scrubUtf8([
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

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function scrubUtf8(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::scrubValue($item);
        }
        return $result;
    }

    private static function scrubValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::scrubString($value);
        }
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return self::scrubUtf8($value);
        }
        return $value;
    }

    /**
     * Pass valid UTF-8 through; otherwise try Windows-1252 / ISO-8859-1,
     * then `iconv //IGNORE` to drop unsalvageable bytes.
     */
    private static function scrubString(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $repaired = self::repairGarbled($value);
        if ($repaired !== null) {
            return $repaired;
        }
        $salvaged = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return $salvaged === false ? '' : $salvaged;
    }

    /**
     * @return string|null null when neither recovery produced valid UTF-8; caller falls back to `iconv //IGNORE`.
     */
    private static function repairGarbled(string $value): ?string
    {
        $repaired = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (is_string($repaired) && $repaired !== '' && mb_check_encoding($repaired, 'UTF-8')) {
            return $repaired;
        }
        foreach (['Windows-1252', 'ISO-8859-1'] as $encoding) {
            $candidate = @mb_convert_encoding($value, 'UTF-8', $encoding);
            if (mb_check_encoding($candidate, 'UTF-8')) {
                return $candidate;
            }
        }
        return null;
    }
}
