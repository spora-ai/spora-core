<?php

declare(strict_types=1);

namespace Spora\Skills;

use Spora\Tools\Attributes\Tool;

/**
 * Parser for a skill's `allowed-tools` frontmatter value.
 *
 * The field was inert for its whole life: read as a raw `?string`, checked with
 * `is_string()`, carried onto the descriptor, echoed by the API and consumed by
 * nothing. No rule was ever enforced, so the only guidance an author got was an
 * error message naming two separators while the code implemented neither — and
 * the shipped skills that read it took the spec's "space-separated tools" and
 * wrote fully-qualified class names. Spora treats the value as a *declaration*
 * ("the tools this skill uses"), not a grant: there is no pre-approval and no
 * requirement enforcement, so nothing here grants or refuses anything. What the
 * grammar has to do is give a consumer one comparable list.
 *
 * A comma, a fully-qualified class name and the spec's parenthesised
 * `Bash(git:*)` scoped form are all rejected, because accepting them would mean
 * shipping two grammars for a field a UI compares against `#[Tool(name:)]`
 * values. A legal name that no installed tool answers to is not this class's
 * problem — that is a warning, and it lives in {@see SkillValidator}.
 */
final class AllowedTools
{
    /**
     * Ceiling on the entries kept. The only consumer is a per-skill list in the
     * UI, and an unbounded list in a frontmatter block is unbounded work for
     * every scan.
     */
    public const MAX_ENTRIES = 32;

    /**
     * The whitespace-separated entries, trimmed, empties dropped, and
     * unvalidated: {@see SkillValidator} needs the entries as written so it can
     * report which one is malformed.
     *
     * Split on `/\s+/` rather than on a literal space because a YAML folded or
     * multi-line scalar arrives with embedded newlines, and a newline is not a
     * tool name.
     *
     * @return list<string>
     */
    public static function entries(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw));
        if ($parts === false) {
            return [];
        }

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * The declared tool names a consumer can act on: legal names only, deduped
     * in first-seen order, capped.
     *
     * Malformed entries are dropped, not passed through. A value with one is
     * already reported as an error, and the list this feeds is compared against
     * `#[Tool(name:)]` values — a bare `Spora\Tools\MediaTool` would never match
     * one, so forwarding it would buy nothing.
     *
     * @return list<string>
     */
    public static function names(string $raw): array
    {
        $out = [];
        foreach (self::entries($raw) as $entry) {
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
            if (preg_match(Tool::NAME_REGEX, $entry) !== 1 || in_array($entry, $out, true)) {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }
}
