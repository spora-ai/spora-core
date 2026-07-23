<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use DateTimeImmutable;
use Exception;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the Media Archive list DTO from HTTP query parameters.
 *
 * Keeping input parsing outside the controller leaves the controller focused
 * on authorization and response serialization.
 */
final class ListMediaQueryBuilder
{
    public static function fromRequest(Request $request, ?int $userId): ListMediaQuery
    {
        $params = $request->query;

        $mediaTypes = self::parseMediaTypes($params->get('types'));
        $mediaType  = self::parseMediaType($params->get('type'));

        return new ListMediaQuery(
            // `types=` wins over `type=` when both are supplied — the picker
            // sends the multi-value form. A singular `type` is still
            // accepted for backward compatibility with the existing media
            // archive plugin which sends `?type=image`.
            mediaType: $mediaTypes === null ? $mediaType : null,
            mediaTypes: $mediaTypes,
            agentId: self::parseAgentId($params->get('agent_id')),
            userId: $params->get('scope') === 'mine' ? $userId : null,
            pluginSlug: self::parseString($params->get('plugin_slug')),
            toolName: self::parseString($params->get('tool_name')),
            from: self::parseDate($params->get('from')),
            to: self::parseDate($params->get('to')),
            search: self::parseString($params->get('q')),
            sort: self::parseSort($params->get('sort')),
            uploadSource: self::parseUploadSource($params->get('source')),
            page: self::parseInt($params->get('page'), 1),
            perPage: self::parseInt($params->get('per_page'), ListMediaQuery::PER_PAGE_DEFAULT),
        );
    }

    private static function parseMediaType(mixed $raw): ?MediaType
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return MediaType::tryFrom(strtolower($raw));
    }

    /**
     * Parse the `?types=image,document` multi-value filter. Unknown tokens
     * are dropped silently so a typo from an older client doesn't crash
     * the listing endpoint. Empty input → null (no filter applied).
     *
     * @return list<MediaType>|null
     */
    private static function parseMediaTypes(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $out = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $token) {
            $type = MediaType::tryFrom(strtolower(trim($token)));
            // Drop unknown tokens and the explicit `unknown` sentinel —
            // the picker only ever asks for image/document.
            if ($type === null || $type === MediaType::Unknown) {
                continue;
            }
            $out[] = $type;
        }
        return $out === [] ? null : $out;
    }

    private static function parseDate(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }

    private static function parseAgentId(mixed $raw): ?int
    {
        if (!is_string($raw) || $raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private static function parseSort(mixed $raw): string
    {
        return is_string($raw) ? $raw : ListMediaQuery::SORT_CREATED_DESC;
    }

    /**
     * Parse the `?source=upload|tool|all` filter. Returns null for missing,
     * empty, `'all'` (no filter), or unknown values — mirroring the typo
     * tolerance of {@see self::parseMediaTypes()}. A typo from an older
     * client must not crash the listing endpoint.
     */
    private static function parseUploadSource(mixed $raw): ?string
    {
        $normalised = self::normaliseUploadSource($raw);
        if ($normalised === null) {
            return null;
        }
        return self::allowedUploadSource($normalised);
    }

    /**
     * Coerce the raw `?source=` value to a lowercased, trimmed string, or
     * null if missing / empty / the `'all'` sentinel. Split out so the
     * public `parseUploadSource()` stays under the 3-return brain-overload
     * ceiling — same refactor we did on `MediaAssetSerializer::scrubString`.
     */
    private static function normaliseUploadSource(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $value = strtolower(trim($raw));
        return $value === ListMediaQuery::UPLOAD_SOURCE_ALL ? null : $value;
    }

    /**
     * Whitelist check. Anything outside the documented
     * `upload` / `tool` set is dropped silently so a typo from an older
     * client doesn't crash the listing endpoint.
     */
    private static function allowedUploadSource(string $value): ?string
    {
        return in_array($value, ListMediaQuery::ALLOWED_UPLOAD_SOURCES, true) ? $value : null;
    }

    private static function parseString(mixed $raw): ?string
    {
        return is_string($raw) ? $raw : null;
    }

    private static function parseInt(mixed $raw, int $default): int
    {
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : $default;
    }
}
