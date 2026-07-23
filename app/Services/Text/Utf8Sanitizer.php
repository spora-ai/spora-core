<?php

declare(strict_types=1);

namespace Spora\Services\Text;

/**
 * Coerces a string (or array of strings) to valid UTF-8 before it reaches
 * any column that JsonResponse or the LLM driver might serialize.
 *
 * Two failure modes it repairs:
 *
 *   1. Single-byte / Latin-1 bytes that were saved by legacy code paths
 *      before utf8mb4 became the connection default (see
 *      {@see \Spora\Core\Database}).
 *   2. Stray invalid bytes (0x80, 0xC0, etc.) embedded inside otherwise-
 *      valid UTF-8 — a tool result that the LLM driver failed to decode,
 *      or a binary blob accidentally routed through a TEXT column.
 *
 * Why: a non-UTF-8 byte inside a JsonResponse triggers `json_encode` to
 * throw `Malformed UTF-8 characters, possibly incorrectly encoded` at
 * serialize time — long after the row was persisted. Sanitizing at the
 * write site closes the gap that lets bad bytes ever reach the DB.
 */
final class Utf8Sanitizer
{
    /**
     * Dispatch on type: strings get scrubbed, arrays get recursed,
     * anything else passes through. Use this when a value's shape
     * isn't known at compile time — request bodies, Eloquent
     * `->fill([...])` payloads, JSON decoded back into PHP.
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::scrubString($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::scrub($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Cheap, allocation-free validity check for callers that want to
     * gate on UTF-8 without mutating (e.g. emit a warning, drop the
     * payload, fall back to a placeholder).
     */
    public static function isValid(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /**
     * Pass valid UTF-8 through unchanged. Otherwise delegate to repairGarbled
     * which tries the two-byte encodings Windows-1252 / ISO-8859-1 (every
     * byte is defined in at least one, so the salvage chain always succeeds
     * for any PHP string input). A final `iconv //IGNORE` pass strips any
     * bytes that survived both attempts — a defensive guard for the
     * hypothetical case where the encoding chain can't recognise the input.
     *
     * Always returns a string — never `false`.
     */
    public static function scrubString(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $repaired = self::repairGarbled($value);
        if ($repaired !== null) {
            return $repaired;
        }
        $salvaged = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return $salvaged === false ? '' : $salvaged;
    }

    /**
     * @return string|null null when neither recovery produced valid UTF-8;
     *                      caller falls back to `iconv //IGNORE`.
     */
    private static function repairGarbled(string $value): ?string
    {
        // iconv //IGNORE is the cheapest of the three paths and the right
        // tool when the input is mostly valid UTF-8 with a few stray invalid
        // bytes — those get dropped and the surrounding text is preserved.
        // Only fall through to mb_convert_encoding if iconv dropped everything
        // (returns '') or produced an invalid result, because mb_convert_encoding
        // reinterprets the entire byte sequence as the named encoding and is
        // destructive for inputs that weren't actually in that encoding.
        $repaired = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (is_string($repaired) && $repaired !== '' && mb_check_encoding($repaired, 'UTF-8')) {
            return $repaired;
        }
        // Windows-1252 first because it covers 0x80–0x9F (smart quotes,
        // em-dashes, the Euro sign) that ISO-8859-1 leaves as control
        // characters. Then ISO-8859-1 as the universal fallback.
        foreach (['Windows-1252', 'ISO-8859-1'] as $encoding) {
            $candidate = @mb_convert_encoding($value, 'UTF-8', $encoding);
            if (mb_check_encoding($candidate, 'UTF-8')) {
                return $candidate;
            }
        }
        return null;
    }
}
