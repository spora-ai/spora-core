<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\MediaAsset;
use Throwable;

/**
 * Contract for producers that mint a derivative of a media asset.
 *
 * Plugins ship their own implementations (e.g. an OCR plugin that turns
 * a scanned document into text, or a Typst plugin that renders a source
 * document into PDF/PNG/SVG) by calling
 * {@see MediaDerivativeProducerDiscovery::add()} from a
 * {@see \Spora\Events\ContainerBuildingEvent} subscriber — the same
 * registration shape the MIME-refiner registry uses, so plugin authors
 * only learn one. The list holds FQCNs and the consumer resolves them
 * through the container, so a producer may take constructor arguments;
 * bind anything the container cannot autowire (an interface) on the same
 * event.
 *
 * `supportedSourceFormats()` doubles as the upload allowlist — core's
 * {@see MediaAllowedTypesService} unions every registered producer's source
 * formats into the accepted MIME types, so registering a producer is what
 * makes a *document* type uploadable. The union drops `image/*` on purpose:
 * image uploads are governed by the agent's `supportsImageInput()` and the
 * `allowed_image_types` config instead, so a thumbnail producer cannot route
 * around the operator's image policy.
 *
 * `supportedSourceFormats()` and `supportedDerivativeFormats()` are
 * advisory hints the controller uses to short-list producers; the
 * producer itself is the source of truth on accept/reject because the
 * hint list can be stale and the producer may need to introspect bytes
 * the controller has not seen.
 *
 * `produce()` may throw — the controller catches and surfaces the
 * exception's message as a 422.
 */
interface MediaDerivativeProducerInterface
{
    /**
     * Source formats this producer accepts. Lowercase MIMEs and/or
     * file extensions (no leading dot). Empty arrays are allowed but
     * mean the producer never matches.
     *
     * @return list<string>
     */
    public function supportedSourceFormats(): array;

    /**
     * Derivative formats this producer can emit. Lowercase short
     * identifiers (`pdf`, `png`, `jpeg`, `svg`, `txt`, …). The wire
     * format emits them verbatim as `format`.
     *
     * @return list<string>
     */
    public function supportedDerivativeFormats(): array;

    /**
     * Stable slug identifying the producer's plugin — written to
     * `media_derivatives.producer_plugin` for attribution. Mirrors
     * `media_assets.plugin_slug`.
     */
    public function pluginSlug(): string;

    /**
     * Stable operation name — written to
     * `media_derivatives.producer_operation`. Mirrors
     * `media_assets.tool_name`.
     */
    public function operationName(): string;

    /**
     * Produce a derivative from `$source`. May throw any `Throwable` —
     * the caller maps it to a 422 response.
     *
     * @param array<string, mixed> $options
     *
     * @throws Throwable
     */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput;
}
