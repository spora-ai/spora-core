<?php

declare(strict_types=1);

namespace Spora\Core;

/**
 * Helpers for merging Spora's three-layer config (defaults + `config.php`
 * + env overrides) without losing nested keys.
 *
 * Extracted from `ContainerDefinitions` to keep that class under the
 * SonarQube S1448 method cap.
 */
final class ConfigMerger
{
    /**
     * Deep-merge `$overrides` into `$base` for associative maps, but
     * replace list (numerically-indexed) arrays atomically.
     *
     * Precedence (last write wins):
     *   `$base` < `$fileConfig` < `$envOverrides`
     *
     * Without this helper, dotted env keys like
     * `media_archive.fetch_timeout_seconds` were stored as a literal
     * top-level key in the overrides array and never reached consumers
     * reading `$config['media_archive']['fetch_timeout_seconds']`.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function merge(array $base, array ...$overrides): array
    {
        foreach ($overrides as $layer) {
            foreach ($layer as $key => $value) {
                if (
                    isset($base[$key])
                    && is_array($base[$key]) && is_array($value)
                    && array_is_list($base[$key]) === array_is_list($value)
                    && !array_is_list($value)
                ) {
                    $base[$key] = self::merge($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            }
        }
        return $base;
    }

    /**
     * Parse the `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES` env value.
     *
     * Returns null when the env var is unset (caller falls back to
     * defaults). Returns an empty array when the env var is set but
     * empty — that means the operator explicitly disabled image
     * uploads. Both states must be distinguishable from
     * `['png','jpeg','webp']` (the built-in default).
     *
     * Whitespace is trimmed; tokens are lowercased; `jpg`/`jpeg`
     * collapse to `jpeg`. SVG variants are rejected explicitly. Order
     * and duplicates are collapsed to the first occurrence.
     *
     * @return list<string>|null
     */
    public static function parseImageTypesCsv(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $tokens = preg_split('/[\\s,]+/', trim($raw));
        if ($tokens === false || implode('', $tokens) === '') {
            return [];
        }
        $normalized = [];
        $seen = [];
        foreach ($tokens as $token) {
            $t = strtolower(trim($token));
            if ($t === '') {
                continue;
            }
            $t = ltrim($t, '.');
            // SVG is excluded pending a sanitization pass.
            if ($t === 'svg' || $t === 'svg+xml') {
                continue;
            }
            $alias = $t === 'jpg' ? 'jpeg' : $t;
            if (!isset($seen[$alias])) {
                $seen[$alias] = true;
                $normalized[] = $alias;
            }
        }
        return $normalized;
    }
}
