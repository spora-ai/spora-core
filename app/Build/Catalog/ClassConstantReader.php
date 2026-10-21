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
