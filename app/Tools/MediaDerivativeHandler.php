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
    public function __construct(
        private MediaAssetSerializer $serializer,
        private MediaDerivativeService $derivatives,
    ) {}

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

    private function derivativeResponse(MediaAsset $parent, MediaAsset $derivative, string $format): ToolResult
    {
        return ToolResult::ok(
            sprintf(
                "Created derivative %s of %s (format=%s, mime=%s).",
                $derivative->id,
                $parent->id,
                $format,
                (string) ($derivative->mime_type ?? 'application/octet-stream'),
            ),
            [
                'derivative_id' => $derivative->id,
                'parent_id'     => $parent->id,
                'format'        => $format,
                'mime_type'     => $derivative->mime_type,
                'byte_size'     => $derivative->byte_size,
                'width'         => $derivative->width,
                'height'        => $derivative->height,
                'asset_url'     => $derivative->publicUrl(),
                'plugin_slug'   => $derivative->plugin_slug,
                'tool_name'     => $derivative->tool_name,
            ],
        );
    }
}
