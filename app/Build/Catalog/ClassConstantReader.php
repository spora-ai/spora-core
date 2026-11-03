<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

/**
 * The literal a class constant holds.
 *
 * A constant resolved from another file is out of scope — the extractor
 * reads one file at a time on purpose — so this reads the constants of
 * the file it is given and nothing else.
 */
final class ClassConstantReader
{
    /** How far from the `const` keyword a declaration may reach. */
    private const DECLARATION_LENGTH = 8;

    public function __construct(
        private readonly TokenStream $tokens,
    ) {}

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string>
     */
    public function constants(array $tokens): array
    {
        $constants = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
                continue;
            }

            $constant = $this->declaration($tokens, $i);
            if ($constant !== null) {
                $constants[$constant[0]] = $constant[1];
            }
        }

        return $constants;
    }

    /**
     * The literal a `self::MSG_X` reference resolves to, and the line the
     * reference is written on.
     *
     * A message held in a constant is still a message an operator reads,
     * so it belongs in the catalogue like any other — the constant is how
     * the code says it once, not how it says it to nobody.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant the tokens of the reference
     * @param array<string, string>                           $constants the constants of the file that declares it
     * @return ?array{0: string, 1: int} message, line
     */
    public function resolveSelfConstant(array $significant, array $constants): ?array
    {
        $name = $this->selfConstantName($significant);

        if ($name === null || !isset($constants[$name[0]])) {
            return null;
        }

        return [$constants[$name[0]], $name[1]];
    }

    /**
     * The name and line a `self::MSG_X` reference names.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant
     * @return ?array{0: string, 1: int} name, line
     */
    private function selfConstantName(array $significant): ?array
    {
        $scope = $significant[0] ?? null;
        $separator = $significant[1] ?? null;
        $name = $significant[2] ?? null;

        if (!is_array($scope) || $scope[0] !== T_STRING || $scope[1] !== 'self') {
            return null;
        }

        if (!is_array($separator) || $separator[0] !== T_DOUBLE_COLON) {
            return null;
        }

        return is_array($name) && $name[0] === T_STRING
            ? [$name[1], $name[2]]
            : null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: string, 1: string}|null
     */
    private function declaration(array $tokens, int $index): ?array
    {
        $name = $this->name($tokens, $index);
        if ($name === null) {
            return null;
        }

        $literal = $this->literal($tokens, $index);
        if ($literal === null) {
            return null;
        }

        return [$name, $literal];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function name(array $tokens, int $index): ?string
    {
        for ($j = $index + 1, $end = min($index + self::DECLARATION_LENGTH, count($tokens)); $j < $end; $j++) {
            $token = $tokens[$j];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            return $token[1];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function literal(array $tokens, int $index): ?string
    {
        for ($j = $index + 1, $end = min($index + self::DECLARATION_LENGTH, count($tokens)); $j < $end; $j++) {
            $token = $tokens[$j];
            if (!is_array($token)) {
                if ($token === ';') {
                    break;
                }
                continue;
            }
            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            return $this->tokens->decode($token[1]);
        }

        return null;
    }
}
