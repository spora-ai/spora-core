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

        // Authenticated listings always use the ownership union: the caller's
        // uploads plus media associated with agents they own. Invalid or
        // missing ownership values resolve to this safe default.
        $ownership = self::parseOwnership($params->get('ownership'));

        return new ListMediaQuery(
            // `types=` wins over `type=` when both are supplied — the picker
            // sends the multi-value form. A singular `type` is still
            // accepted for backward compatibility with the existing media
            // archive plugin which sends `?type=image`.
            mediaType: $mediaTypes === null ? $mediaType : null,
            mediaTypes: $mediaTypes,
            agentId: self::parseAgentId($params->get('agent_id')),
            userId: null,
            pluginSlug: self::parseString(
                // `plugin` is the spelling the Media Archive panel sends;
                // `plugin_slug` is core's documented name. The panel is a
                // separately released package, so its spelling is the one
                // in the field and both are accepted — dropping the alias
                // would silently break the released panel's plugin chip.
                self::firstOf($params->get('plugin_slug'), $params->get('plugin')),
            ),
            toolName: self::parseString($params->get('tool_name')),
            from: self::parseDate($params->get('from')),
            to: self::parseDate($params->get('to')),
            search: self::parseString(
                // `search` is the spelling the Media Archive panel sends;
                // `q` is core's documented name. Same reasoning as the
                // plugin filter above.
                self::firstOf($params->get('q'), $params->get('search')),
            ),
            sort: self::parseSort($params->get('sort')),
            uploadSource: self::parseUploadSource($params->get('source')),
            ownership: $ownership ?? ListMediaQuery::OWNERSHIP_MINE,
            agentOwnerUserId: $userId,
            // No intersection here — the controller runs the request
            // values through `PrincipalResolver::visiblePrincipalIds()`
            // before the service touches them, so the DTO only ever
            // carries ids the caller can act as. See `MediaArchiveController
            // ::resolvePrincipalFilter()`.
            principalIds: self::parsePrincipalIds($params->all()['principal_id'] ?? null),
            includeTemporary: self::parseBool($params->get('include_temporary'), false),
            folderId: self::parseFolderId($params->all()['folder_id'] ?? null),
            kind: self::parseKind($params->get('kind')),
            page: self::parseInt($params->get('page'), 1),
            perPage: self::parseInt($params->get('per_page'), ListMediaQuery::PER_PAGE_DEFAULT),
        );
    }

    /**
     * The canonical spelling when it carries a value, the alias otherwise.
     */
    private static function firstOf(mixed $canonical, mixed $alias): mixed
    {
        if (is_string($canonical) && $canonical !== '') {
            return $canonical;
        }

        return $alias;
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

    /**
     * Parse the `?ownership=` filter. Returns null for missing, empty,
     * or any value other than `'mine'` — mirroring the typo tolerance
     * of {@see self::parseUploadSource()} and {@see self::parseMediaTypes()}.
     * A typo from an older client must not crash the listing endpoint.
     * Note: there is NO `'all'` value — see {@see ListMediaQuery::OWNERSHIP_MINE}
     * for the security rationale (an authenticated user must never be
     * able to dump the full table via a query string). The null return
     * triggers the `mine` default in `fromRequest()`.
     */
    private static function parseOwnership(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $value = strtolower(trim($raw));
        return $value === ListMediaQuery::OWNERSHIP_MINE ? $value : null;
    }

    private static function parseString(mixed $raw): ?string
    {
        return is_string($raw) ? $raw : null;
    }

    /**
     * Parse `?folder_id=` into the folder the listing is scoped to.
     *
     * The array-bracket form (`?folder_id[]=…`) is accepted because the
     * Media Archive panel's convention for a repeated id is the
     * bracket form (`principal_id[]`); a multi-select folder filter would
     * arrive that way. Only the scalar is honoured for now — a list
     * would need a `WHERE folder_id IN (…)`, and a half-implemented
     * multi-folder filter that silently returns one folder's rows would
     * be worse than none.
     *
     * An unknown folder id is not an error: the service resolves it and
     * yields no rows when it belongs to another principal.
     */
    private static function parseFolderId(mixed $raw): ?string
    {
        $value = is_array($raw) ? reset($raw) : $raw;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Parse `?kind=` into the advisory mark the listing is scoped to.
     *
     * An unknown value is dropped silently — mirroring the typo tolerance
     * of the other filters — so the listing stays unfiltered instead of
     * failing.
     */
    private static function parseKind(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $value = strtolower(trim($raw));

        return in_array($value, ListMediaQuery::ALLOWED_KINDS, true) ? $value : null;
    }

    private static function parseInt(mixed $raw, int $default): int
    {
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : $default;
    }

    /**
     * Coerce `?include_temporary=…` (and friends) into a strict bool.
     * `FILTER_VALIDATE_BOOLEAN` normalises `true` / `1` / `"true"` /
     * `"1"` / `"yes"` to true; everything else (including `null`,
     * missing key, `"false"`, `"0"`, typos) falls back to the default.
     * Mirrors the boolean-flag coercion in
     * {@see \Spora\Http\AgentController::coerceBooleanFlags()}.
     */
    private static function parseBool(mixed $raw, bool $default): bool
    {
        if ($raw === null) {
            return $default;
        }
        $normalised = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $normalised ?? $default;
    }

    /**
     * Parse `?principal_id=` (repeatable) into a list<int>. Accepts all
     * three syntaxes Symfony exposes:
     *   `?principal_id=10`              — single value, scalar.
     *   `?principal_id=10&principal_id=20` — repeated, becomes array.
     *   `?principal_id[]=10&principal_id[]=20` — explicit array.
     * `query->all()` returns scalar OR array depending on repetition, so we
     * normalise through `is_array()` first.
     *
     * Unknown / non-integer tokens are silently dropped so a typo from an
     * older client doesn't crash the listing endpoint. Empty / missing
     * input returns null (no filter) so the controller can fall back to
     * the legacy `agentOwnerUserId`-based ownership union.
     *
     * @return list<int>|null
     */
    private static function parsePrincipalIds(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $values = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($values as $v) {
            if (is_int($v)) {
                $ids[] = $v;
                continue;
            }
            if (is_string($v) && ctype_digit($v)) {
                $ids[] = (int) $v;
            }
        }
        return $ids === [] ? null : array_values(array_unique($ids));
    }
}
