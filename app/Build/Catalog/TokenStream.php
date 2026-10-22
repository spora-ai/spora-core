<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

/**
 * The token-level primitives every harvest kind needs.
 *
 * `token_get_all` reports whitespace and comments as tokens of their own,
 * so every scan has to step over them. Doing that in one place is what
 * keeps the harvests from disagreeing about what a "significant" token
 * is — a disagreement here would mean the same source read two ways.
 */
final class TokenStream
{
    /** @var list<int> */
    private const INSIGNIFICANT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    public function isInsignificant(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], self::INSIGNIFICANT, true);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    public function nextSignificantIndex(array $tokens, int $from): int
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            if ($this->isInsignificant($tokens[$i])) {
                continue;
            }
            return $i;
        }

        return $from;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    public function nextSignificant(array $tokens, int $index): array|string|null
    {
        for ($i = $index, $count = count($tokens); $i < $count; $i++) {
            if ($this->isInsignificant($tokens[$i])) {
                continue;
            }
            return $tokens[$i];
        }

        return null;
    }

    /**
     * The `$count` tokens before `$index` that are not whitespace or
     * comments, nearest first. PHP puts a space on either side of `->`
     * whenever the author wrote one, so a fixed offset reads the wrong
     * token about half the time.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    public function precedingSignificant(array $tokens, int $index, int $count): array
    {
        $significant = [];

        for ($i = $index - 1; $i >= 0 && count($significant) < $count; $i--) {
            if ($this->isInsignificant($tokens[$i])) {
                continue;
            }
            $significant[] = $tokens[$i];
        }

        return $significant;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return ?array{0: int, 1: string, 2: int}
     */
    public function sprintfFormat(array $tokens, int $index): ?array
    {
        $cursor = $this->nextSignificantIndex($tokens, $index + 1);
        if (($tokens[$cursor] ?? null) !== '(') {
            return null;
        }

        $cursor = $this->nextSignificantIndex($tokens, $cursor + 1);
        $literal = $tokens[$cursor] ?? null;
        if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return $literal;
    }

    /**
     * The text a string literal carries.
     *
     * Single-quoted PHP escapes only `\'` and `\\`; everything else is
     * literal, and un-escaping them would corrupt copy that genuinely
     * contains a backslash (a Windows path in a hint, a regex).
     */
    public function decode(string $literal): string
    {
        $quote = $literal[0] ?? "'";
        $inner = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return (string) stripcslashes($inner);
    }
}
