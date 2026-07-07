<?php

declare(strict_types=1);

namespace Spora\Plugins\Concerns;

use InvalidArgumentException;
use LogicException;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;

/**
 * Opt-in trait for plugin tool classes. Removes the `hex2bin` +
 * `AssetStore::store()` boilerplate from each tool.
 *
 * Plugins that can't `use` a trait (e.g. their base class is sealed or
 * already consumed a conflicting slot) can call
 * {@see AssetStore::store()} directly plus the static helpers in
 * {@see \Spora\Tools\MediaEmbed}. The trait is a convenience, not a
 * requirement.
 *
 * The `setAssetStore()` setter is invoked by PHP-DI at construction
 * time (auto-wired from the container). Plugins that build tools via
 * reflection or hand-roll their own factories should call it explicitly.
 *
 * The `setMediaArchive()` setter is optional — tools that don't care
 * about archive ingestion simply never call `archiveMedia()`. The
 * `archiveMedia()` convenience wraps the typical
 * "I have raw bytes, write them, sniff, record" flow into one call.
 */
trait StoresBinaryAssets
{
    private ?AssetStore $assetStore = null;
    private ?MediaArchiveService $mediaArchive = null;

    /**
     * Called by PHP-DI auto-resolution when the container constructs the
     * plugin's tool class. Safe to call multiple times; the last write wins.
     */
    public function setAssetStore(AssetStore $store): void
    {
        $this->assetStore = $store;
    }

    /**
     * Auto-wired by PHP-DI alongside {@see setAssetStore()}. Tools that
     * never call {@see archiveMedia()} can ignore this injection.
     */
    public function setMediaArchive(MediaArchiveService $mediaArchive): void
    {
        $this->mediaArchive = $mediaArchive;
    }

    public function assetStore(): AssetStore
    {
        if ($this->assetStore === null) {
            throw new LogicException(
                'AssetStore has not been injected into ' . static::class
                . '. Did the DI container miss the auto-wiring?',
            );
        }
        return $this->assetStore;
    }

    public function mediaArchive(): MediaArchiveService
    {
        if ($this->mediaArchive === null) {
            throw new LogicException(
                'MediaArchiveService has not been injected into ' . static::class
                . '. Did the DI container miss the auto-wiring?',
            );
        }
        return $this->mediaArchive;
    }

    /**
     * Decode a hex payload (with odd-length guard) and persist via
     * {@see AssetStore::store()}. Returns the canonical
     * `[url, mode]` pair so callers can decide what to do with `mode`
     * (typically: surface in `ToolResult::$data['asset_mode']` for the
     * UI).
     *
     * @return array{0: string, 1: string}  [url, mode]
     */
    protected function embedHex(string $hex, string $mime, string $filename): array
    {
        if (strlen($hex) % 2 !== 0) {
            throw new InvalidArgumentException(
                'Hex payload has odd length (' . strlen($hex) . ' chars); refusing to decode.',
            );
        }
        $decoded = hex2bin($hex);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('Hex payload decoded to empty bytes.');
        }
        $ref = $this->assetStore()->store($decoded, mime: $mime, filename: $filename);
        return [$ref->url, $ref->mode];
    }

    /**
     * One-call ingest helper for tools that already have bytes in hand.
     * Equivalent to calling `mediaArchive()->ingest()` with a `MediaIngestRequest`
     * constructed from the same arguments — the convenience exists so
     * plugin authors don't have to import the DTO for the common case.
     *
     * Any `$context` keys override the matching {@see MediaIngestRequest}
     * constructor argument (`agentId`, `taskId`, `toolCallId`,
     * `pluginSlug`, `toolName`, `prompt`, `tags`, `metadata`). The bytes
     * are passed through directly; hex/base64 are handled by the
     * service's main ingest method.
     *
     * @param array<string, mixed> $context
     */
    protected function archiveMedia(
        string $bytes,
        string $mime,
        ?string $filename,
        MediaType $mediaType,
        array $context = [],
    ): \Spora\Models\MediaAsset {
        $request = new MediaIngestRequest(
            bytes: $bytes,
            mime: $mime,
            filename: $filename,
            mediaType: $mediaType,
            agentId: isset($context['agentId']) ? (int) $context['agentId'] : null,
            taskId: isset($context['taskId']) ? (int) $context['taskId'] : null,
            toolCallId: isset($context['toolCallId']) ? (int) $context['toolCallId'] : null,
            pluginSlug: isset($context['pluginSlug']) ? (string) $context['pluginSlug'] : null,
            toolName: isset($context['toolName']) ? (string) $context['toolName'] : null,
            prompt: isset($context['prompt']) ? (string) $context['prompt'] : null,
            tags: isset($context['tags']) && is_array($context['tags']) ? $context['tags'] : null,
            metadata: isset($context['metadata']) && is_array($context['metadata']) ? $context['metadata'] : null,
            width: isset($context['width']) ? (int) $context['width'] : null,
            height: isset($context['height']) ? (int) $context['height'] : null,
            durationSeconds: isset($context['durationSeconds']) ? (float) $context['durationSeconds'] : null,
            byteSize: strlen($bytes),
        );
        return $this->mediaArchive()->ingest($request);
    }
}
