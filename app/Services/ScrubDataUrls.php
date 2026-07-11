<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Defense-in-depth helper that strips `data:` URIs from any string before
 * it gets persisted to chat history or replayed to the LLM. Applied at
 * every "tool result / task history append" chokepoint so a future plugin
 * that bypasses {@see MediaArchive\MediaArchiveService}
 * can't pollute context with multi-MB base64 payloads.
 *
 * The pattern matches `data:<mime>;base64,<payload>` — not raw `data:`
 * URLs which would also mangle legitimate uses (e.g., SVG `<a href>` is
 * blocked by the frontend sanitizer; this just covers the LLM side).
 */
final class ScrubDataUrls
{
    public const PLACEHOLDER = '[data-omitted]';

    public static function scrub(string $content): string
    {
        return preg_replace_callback(
            '#data:[a-zA-Z0-9.\-+/]+;base64,[A-Za-z0-9+/=]+#',
            static fn(array $m): string => self::PLACEHOLDER,
            $content,
        ) ?? $content;
    }
}
