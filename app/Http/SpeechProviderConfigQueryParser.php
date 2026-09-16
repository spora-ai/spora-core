<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Query-parameter helpers for {@see SpeechProviderConfigController}'s
 * list endpoint. The endpoint exposes two optional filters:
 *
 *   - `?agent_id=N` — per-agent principal scope (agent-settings page)
 *   - `?group_id=N` — per-group scope (group-settings page)
 *
 * Both accept a positive integer; missing, empty, non-numeric, zero,
 * or negative values fall through to `null` so the controller can
 * branch on absence rather than erroring.
 *
 * Extracted off the controller so the controller stays under the
 * SonarCloud S1448 20-method-per-class ceiling (the parser helpers
 * were the bulk of the new methods added for the agent-scope fix;
 * keeping them here drops the controller from 22 → 20 methods).
 */
final class SpeechProviderConfigQueryParser
{
    public static function parseAgentId(Request $request): ?int
    {
        return self::parsePositiveInt($request, 'agent_id');
    }

    public static function parseGroupId(Request $request): ?int
    {
        return self::parsePositiveInt($request, 'group_id');
    }

    private static function parsePositiveInt(Request $request, string $name): ?int
    {
        $raw = $request->query->get($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
