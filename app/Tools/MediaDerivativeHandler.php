<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalContext;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Implements the `list_derivatives` and `create_derivative`
 * {@see MediaTool} operations. Extracted out of MediaTool to keep
 * that class below Sonar's per-class method budget and to centralise
 * the `MediaAssetSerializer::derivativeRowsFor()` /
 * `MediaDerivativeService::createFromRequest()` calls in one place
 * — the controller and the tool share the same wire shape.
 *
 * Inputs arrive already-resolved (`$parent` is the MediaAsset the
 * caller has scope-checked); the handler does not re-validate scope
 * or auth. Failures flow back through {@see ToolResult::fail()}
 * with operator-friendly hints.
 */
final readonly class MediaDerivativeHandler
{
    /**
     * Cap on the extracted text `get_media` and `get_source` inline. These
     * ops are LLM-facing, so the full body stays on the derivative and the
     * model is told to call `get_source` for it. Deliberately distinct from
     * `AttachmentRowRenderer::MAX_INLINE_TEXT_BYTES`, which bounds a chat
     * attachment.
     */
    public const PREVIEW_BYTES = 8 * 1024;

    public function __construct(
        private MediaAssetSerializer $serializer,
        private MediaDerivativeService $derivatives,
        private MediaSourceReader $sourceReader,
    ) {}

    /**
     * The asset's extracted text from its `md` derivative, or null when it
     * has none.
     *
     * Pure read: `get_source` and `get_media` surface what ingest or an
     * explicit `create_derivative` already minted, and neither mints one on
     * the spot. A lazy create here would make a read operation write to the
     * archive — a different approval story than the one these ops hold.
     */
    public function readTextDerivative(MediaAsset $asset): ?string
    {
        $derivative = $this->derivatives->findTextDerivative($asset);

        return $derivative !== null ? $this->readDerivativeBytes($derivative) : null;
    }

    /**
     * Derivative bytes, or null when they cannot be read or the producer
     * returned nothing. An empty extraction is treated as absent on purpose —
     * a scanned PDF with no text layer has no derivative worth surfacing, and
     * an empty block reads as a broken tool to the LLM.
     */
    public function readDerivativeBytes(MediaAsset $derivative): ?string
    {
        $bytes = $this->sourceReader->read($derivative);

        return ($bytes === null || trim($bytes) === '') ? null : $bytes;
    }

    /**
     * Truncate extracted text to the LLM-facing cap, appending a pointer to
     * `get_source` when anything was dropped.
     */
    public function previewExtractedText(string $text, int $limit = self::PREVIEW_BYTES): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit)
            . "\n\n[…truncated — call `get_source` for the full extracted text]";
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    public function listDerivatives(MediaAsset $parent, array $arguments): ToolResult
    {
        $rows = $this->serializer->derivativeRowsFor($parent);
        $formatFilter = strtolower(trim((string) ($arguments['format'] ?? '')));
        if ($formatFilter !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => strtolower((string) ($row['format'] ?? '')) === $formatFilter,
            ));
        }

        $count = count($rows);
        $suffix = $formatFilter !== '' ? " matching format \"{$formatFilter}\"" : '';

        return ToolResult::ok(
            "Found {$count} derivative(s) of {$parent->id}{$suffix}.",
            [
                'parent_id'   => $parent->id,
                'format'      => $formatFilter !== '' ? $formatFilter : null,
                'count'       => $count,
                'derivatives' => $rows,
            ],
        );
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    public function createDerivative(
        MediaAsset $parent,
        array $arguments,
        ?int $userId,
        ?PrincipalContext $context,
    ): ToolResult {
        $inputs = $this->validateInputs($arguments);
        if ($inputs instanceof ToolResult) {
            return $inputs;
        }

        try {
            $derivative = $this->derivatives->createFromRequest(
                parent: $parent,
                format: $inputs['format'],
                options: $inputs['options'],
                userId: $userId,
                context: $context,
            );
        } catch (Throwable $e) {
            return $this->derivativeFailure($e, $parent, $inputs['format']);
        }

        return $this->derivativeResponse($parent, $derivative, $inputs['format']);
    }

    /**
     * Validate `format` (required) and `options` (must be an array
     * when present). Returns the normalised inputs on success, or a
     * `ToolResult::fail(...)` the caller should return as-is.
     *
     * @param  array<string, mixed> $arguments
     * @return ToolResult | array{format: string, options: array<string, mixed>}
     */
    private function validateInputs(array $arguments): ToolResult|array
    {
        $format = strtolower(trim((string) ($arguments['format'] ?? '')));
        if ($format === '') {
            return ToolResult::fail('`format` is required for `create_derivative`. '
                . 'Call `list_derivatives(asset_id: <parent>)` to discover the formats the registered producers support.');
        }

        $options = $arguments['options'] ?? [];
        if (!is_array($options)) {
            return ToolResult::fail('`options` must be an object mapping producer-specific knobs (e.g. {"page": 0, "ppi": 144}).');
        }

        return ['format' => $format, 'options' => $options];
    }

    private function derivativeFailure(Throwable $e, MediaAsset $parent, string $format): ToolResult
    {
        if ($e instanceof \Spora\Services\MediaArchive\Exceptions\NoDerivativeProducerException) {
            return ToolResult::fail($e->getMessage()
                . ' Call `list_derivatives(asset_id: ' . $parent->id . ')` to discover the formats the registered producers support.');
        }

        return ToolResult::fail(sprintf(
            '`create_derivative` failed (format=%s): %s',
            $format,
            $e->getMessage(),
        ));
    }

    /**
     * Announces the derivative AND embeds it. The embed is what makes the
     * result visible: without it the operator sees a sentence naming an id
     * and has to call `get_media` to find out what came out, which is the
     * one thing an operation that just minted a file should not require.
     */
    private function derivativeResponse(MediaAsset $parent, MediaAsset $derivative, string $format): ToolResult
    {
        $derivativeUrl = $derivative->publicUrl();
        $altText       = (string) ($derivative->filename ?? '') ?: $derivative->id;

        $content = sprintf(
            "Created derivative %s of %s (format=%s, mime=%s).\n\n%s\n\n"
            . 'Echo the block above verbatim so the chat UI renders the result.',
            $derivative->id,
            $parent->id,
            $format,
            (string) ($derivative->mime_type ?? 'application/octet-stream'),
            MediaEmbed::forAsset($derivative, $derivative->typedMediaType(), $derivativeUrl, $altText),
        );

        return ToolResult::ok(
            $content,
            [
                'derivative_id' => $derivative->id,
                'parent_id'     => $parent->id,
                'format'        => $format,
                'mime_type'     => $derivative->mime_type,
                'byte_size'     => $derivative->byte_size,
                'width'         => $derivative->width,
                'height'        => $derivative->height,
                'asset_url'     => $derivativeUrl,
                'plugin_slug'   => $derivative->plugin_slug,
                'tool_name'     => $derivative->tool_name,
            ],
        );
    }
}
