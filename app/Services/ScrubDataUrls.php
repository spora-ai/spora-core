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
        return preg_replace_callback(
            '#data:[a-zA-Z0-9.\-+/]+;base64,[A-Za-z0-9+/=]+#',
            static fn(array $m): string => self::PLACEHOLDER,
            $content,
        ) ?? $content;
    }
}
