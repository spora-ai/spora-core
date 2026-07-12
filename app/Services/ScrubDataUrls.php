<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Strips `data:<mime>;base64,<payload>` URIs before content reaches
 * the LLM context. The pattern matches base64 form only — raw `data:`
 * URLs (e.g. SVG `<a href>`) are left alone because the frontend
 * sanitizer already covers them.
 */
final class ScrubDataUrls
{
    public const PLACEHOLDER = '[data-omitted]';

    public static function scrub(string $content): string
    {
        // Case-insensitive on both the scheme and the `base64` marker so
        // uppercase `DATA:...;BASE64,...` payloads (some upstream
        // generators do emit them) are still scrubbed. The `(?:;...)*`
        // group matches zero or more `;key=value` parameters between the
        // MIME and `;base64,` — e.g. `data:text/plain;charset=utf-8;base64,...`
        // — so character-set / boundary hints don't slip a payload past
        // the regex. The `i` flag makes `[a-z]` match both cases, so we
        // don't need a separate `[A-Z]` range. `-` placed at the end of
        // each class is a literal (not a range).
        return preg_replace_callback(
            '~data:[a-z0-9./+-]+(?:;[a-z0-9=.-]+)*;base64,[a-z0-9+/=]+~i',
            static fn(array $m): string => self::PLACEHOLDER,
            $content,
        ) ?? $content;
    }
}
