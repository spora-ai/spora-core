<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaImageBlocks;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Implements the `inspect_image` {@see MediaTool} operation — the one that
 * puts an archive image in front of the model.
 *
 * Split out of MediaTool for the same reason as
 * {@see MediaCreateHandler} and {@see MediaFolderHandler}: MediaTool sits
 * at Sonar's per-class method budget.
 *
 * Two gates stand in front of the operation, and both refuse with a message
 * the model reads rather than with a silent no-op:
 *
 *   - the agent's LLM must accept image content blocks. The gate is the
 *     *resolved* `supportsImageInput()` value — the same value that gates
 *     image uploads — so the archive cannot become a side door around it.
 *   - the asset must be visible under the media tool's `scope` setting.
 *
 * The scope check itself is not repeated here: every per-asset operation
 * shares {@see MediaTool::resolveAssetOrFail()}, and the caller passes it in
 * so there is one implementation of it.
 */
final class MediaInspectImageHandler
{
    /**
     * The raster MIME types the shipped providers accept as an image content
     * block. Anthropic's `media_type` enum is exactly `jpeg|png|gif|webp`,
     * and the other two drivers take the same set. SVG is absent on purpose:
     * it is a script-capable document, not a raster image.
     */
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * The storage modes whose bytes are on hand. An `external` asset lives on
     * someone else's server and is not fetched here.
     */
    private const READABLE_STORAGE_MODES = ['local', 'data_url'];

    /**
     * The refusal for an agent whose LLM cannot read an image.
     *
     * The model is named because a provider's model name is what the
     * operator recognises when they go looking for the configuration, and
     * the setting is named because the remedy is a switch, not a retry.
     */
    private const MSG_NO_IMAGE_INPUT = '`inspect_image` needs a vision-capable LLM, and this agent\'s does not accept '
        . 'image content blocks (model: %s). Enable "Allow images" on the agent\'s LLM configuration, or point the '
        . 'agent at a vision-capable model, then call `inspect_image` again.';

    /**
     * @param  array<string, mixed>                          $arguments
     * @param  callable(string, string): (MediaAsset|ToolResult)     $resolveAsset
     *        The caller's scope check. It receives the message to answer
     *        with when the asset is not visible, and the scope that
     *        message names, and returns the asset or the refusal.
     */
    public function inspect(
        array $arguments,
        int $agentId,
        ?DriverFactory $driverFactory,
        string $scope,
        callable $resolveAsset,
    ): ToolResult {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));

        // The capability gate runs first: an agent whose LLM cannot read an
        // image needs to hear that, not to hear that the asset is missing.
        $driver  = $this->resolveDriver($driverFactory, $agentId);
        $refusal = $this->capabilityRefusal($driver);
        if ($refusal !== null) {
            return $refusal;
        }

        // The scope goes with the message: the refusal names it, and the
        // check that answers it has to decide on the same one.
        $asset = $resolveAsset($this->notVisibleMessage($scope, $assetId), $scope);
        if ($asset instanceof ToolResult) {
            return $asset;
        }

        return $this->attach($asset, $arguments, $driver);
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function attach(MediaAsset $asset, array $arguments, ?LLMDriverInterface $driver): ToolResult
    {
        $mime     = strtolower(trim((string) ($asset->mime_type ?? '')));
        $maxBytes = MediaImageBlocks::maxImageBytesFor($driver);
        $refusal  = $this->mimeRefusal($asset, $mime)
            ?? $this->storageRefusal($asset)
            ?? $this->sizeRefusal($asset, $maxBytes);

        if ($refusal !== null) {
            return $refusal;
        }

        $image = $this->loadImage($asset, $maxBytes);
        if ($image instanceof ToolResult) {
            return $image;
        }

        $question = trim((string) ($arguments['question'] ?? ''));
        $filename = (string) ($asset->filename ?? '');
        if ($filename === '') {
            $filename = $asset->id;
        }

        $text = sprintf(
            'Attached media asset %s (%s, %s, %d bytes) to this result — you can see it now.',
            $asset->id,
            $filename,
            $mime,
            $image['size'],
        );
        $text .= "\n\nQuestion: " . ($question === '' ? 'none given.' : $question);

        return ToolResult::ok(
            $text,
            [
                'asset_id'  => $asset->id,
                'filename'  => $filename,
                'mime_type' => $mime,
                'byte_size' => $image['size'],
                'question'  => $question === '' ? null : $question,
            ],
            [['media_id' => $asset->id, 'kind' => 'image']],
        );
    }

    /**
     * The refusal for a MIME type `inspect_image` does not read.
     */
    private function mimeRefusal(MediaAsset $asset, string $mime): ?ToolResult
    {
        if ($mime === 'image/svg+xml') {
            return ToolResult::fail(sprintf(
                '`inspect_image` does not read SVG (asset %s): an SVG is a script-capable document, not a raster '
                . 'image, and no registered derivative producer rasterises one. The asset has to be converted to a '
                . 'raster image (PNG, JPEG, WebP or GIF) outside Spora, or by the operator, before it can be inspected.',
                $asset->id,
            ));
        }

        if (!in_array($mime, self::IMAGE_MIME_TYPES, true)) {
            return ToolResult::fail(sprintf(
                '`inspect_image` only reads raster images (image/png, image/jpeg, image/webp, image/gif). '
                . 'Asset %s is "%s".',
                $asset->id,
                $mime === '' ? 'unknown' : $mime,
            ));
        }

        return null;
    }

    /**
     * The refusal for an asset whose bytes are not on hand here.
     */
    private function storageRefusal(MediaAsset $asset): ?ToolResult
    {
        if (!in_array($asset->storage_mode, self::READABLE_STORAGE_MODES, true)) {
            return ToolResult::fail(sprintf(
                'Asset %s is stored externally (storage_mode=%s) and has no Spora-side payload, so `inspect_image` '
                . 'cannot read it. Use `get_media` to retrieve its source URL.',
                $asset->id,
                $asset->storage_mode,
            ));
        }

        return null;
    }

    /**
     * The refusal for an asset the stored size already rules out.
     *
     * The stored size is a hint, not the truth: it can be stale, and a
     * stale column must not be what decides whether the bytes fit. It
     * rejects the obvious case without a read; the measurement after the
     * load is the one that counts.
     */
    private function sizeRefusal(MediaAsset $asset, int $maxBytes): ?ToolResult
    {
        $declared = (int) ($asset->byte_size ?? 0);
        if ($declared > $maxBytes) {
            return ToolResult::fail($this->oversizedMessage($asset, $declared, $maxBytes));
        }

        return null;
    }

    /**
     * The image's bytes, or the refusal that says why there are none.
     *
     * @return array{bytes: string, size: int}|ToolResult
     */
    private function loadImage(MediaAsset $asset, int $maxBytes): array|ToolResult
    {
        $bytes = MediaImageBlocks::loadBytes($asset, $this->basePath());
        if ($bytes === null) {
            return ToolResult::fail(sprintf(
                'The bytes of asset %s could not be read (storage_mode=%s). The underlying blob may be missing.',
                $asset->id,
                $asset->storage_mode,
            ));
        }

        // Measured after the load, the order the attachment path uses, so a
        // stale column cannot smuggle an oversized body through.
        $size = strlen($bytes);
        if ($size > $maxBytes) {
            return ToolResult::fail($this->oversizedMessage($asset, $size, $maxBytes));
        }

        return ['bytes' => $bytes, 'size' => $size];
    }

    /**
     * The agent's driver, or null when it cannot be resolved.
     *
     * An agent with no driver cannot read an image, so the capability gate
     * refuses on the same null — but the driver is resolved once here,
     * because the size ceiling depends on which endpoint it is.
     */
    private function resolveDriver(?DriverFactory $driverFactory, int $agentId): ?LLMDriverInterface
    {
        if ($driverFactory === null) {
            return null;
        }

        $agent = Agent::query()->find($agentId);
        if ($agent === null) {
            return null;
        }

        try {
            return $driverFactory->makeFromAgent($agent);
        } catch (Throwable) {
            // An agent whose LLM cannot even be resolved cannot read an
            // image either; the capability refusal says so.
            return null;
        }
    }

    /**
     * The refusal for an agent whose LLM does not take image content blocks.
     *
     * The gate is the driver's *resolved* capability — the operator's toggle
     * first, the model heuristic second — because that is the same value
     * that gates image uploads. A stricter gate would make the archive a
     * side door around it.
     */
    private function capabilityRefusal(?LLMDriverInterface $driver): ?ToolResult
    {
        if ($driver !== null && $driver->supportsImageInput()) {
            return null;
        }

        return ToolResult::fail(sprintf(self::MSG_NO_IMAGE_INPUT, $driver?->getModelName() ?? 'unknown'));
    }

    /**
     * The refusal for an asset the calling agent cannot see.
     *
     * It keeps the existence-hiding posture of the generic "Media asset not
     * found." — the asset is "not visible", never "exists but is forbidden" —
     * while naming the setting that governs the reach and what to do about
     * it. The generic message is a dead end for the model: it cannot tell
     * "no such asset" from "not yours", and it cannot act on either.
     */
    private function notVisibleMessage(string $scope, string $assetId): string
    {
        // The legacy `user` value is an alias for `principal`, so the
        // message names the reach rather than the spelling the operator
        // would not recognise.
        $effective = $scope === 'user' ? 'principal' : $scope;

        if ($effective === 'agent') {
            return sprintf(
                'No media asset %s is visible to this agent. The media tool\'s Library scope is set to "agent", so only '
                . 'assets this agent produced are reachable — an image you uploaded yourself is not one of them. '
                . 'Call `search` to list the assets you can see, or ask the operator to set the media tool\'s '
                . 'Library scope to "principal".',
                $assetId,
            );
        }

        return sprintf(
            'No media asset %s is visible to this agent. The media tool\'s Library scope is set to "%s", so only the '
            . 'assets of your own principal are reachable. Call `search` to list the assets you can see.',
            $assetId,
            $effective,
        );
    }

    private function oversizedMessage(MediaAsset $asset, int $size, int $maxBytes): string
    {
        return sprintf(
            'Asset %s is %d bytes, over the %d-byte ceiling `inspect_image` reads for this endpoint — the image is '
            . 'sent base64-encoded, and the provider caps that form. Render a smaller derivative (e.g. '
            . '`create_derivative(asset_id: %s, format: "thumbnail-256")`) and inspect that instead.',
            $asset->id,
            $size,
            $maxBytes,
            $asset->id,
        );
    }

    /**
     * Where the archive's asset files live.
     */
    private function basePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    }
}
