<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Result of {@see AssetStore::store()}. `$url` is what gets embedded
 * verbatim into a tool result's content markdown — typically as the
 * `src` attribute of an `<audio>`, `<video>`, or `<img>` tag (use
 * {@see \Spora\Tools\MediaEmbed} for the canonical markup snippets).
 *
 * `mode` distinguishes the two backing stores so the UI can display a
 * different affordance (e.g. "local copy, served by Spora" vs "inline")
 * and so tests can assert which strategy was selected.
 */
final readonly class AssetReference
{
    public function __construct(
        public string $url,
        public string $mode,
        public ?string $token = null,
    ) {}
}
