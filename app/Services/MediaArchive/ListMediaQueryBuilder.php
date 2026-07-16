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

        return new ListMediaQuery(
            mediaType: self::parseMediaType($params->get('type')),
            agentId: self::parseAgentId($params->get('agent_id')),
            userId: $params->get('scope') === 'mine' ? $userId : null,
            pluginSlug: self::parseString($params->get('plugin_slug')),
            toolName: self::parseString($params->get('tool_name')),
            from: self::parseDate($params->get('from')),
            to: self::parseDate($params->get('to')),
            search: self::parseString($params->get('q')),
            sort: self::parseSort($params->get('sort')),
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

    private static function parseString(mixed $raw): ?string
    {
        return is_string($raw) ? $raw : null;
    }

    private static function parseInt(mixed $raw, int $default): int
    {
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : $default;
    }
}
