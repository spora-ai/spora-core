<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

/**
 * Messages thrown as exception arguments.
 *
 * The message is operator-facing because the router localizes whatever ends
 * up in the error envelope, and this literal is what a controller's
 * `$e->getMessage()` puts there.
 */
final class ThrownLiteralHarvest implements Harvest
{
    /**
     * Directories whose thrown exception messages can reach an operator.
     *
     * A service throws an exception with an English message; a controller
     * catches it and puts `$e->getMessage()` into the error envelope. The
     * literal therefore lives at the *throw* site, in a directory the
     * envelope harvest never looks at — so without this the message is
     * untranslatable and `i18n:check` would fail on it forever.
     *
     * `app/Tools` is excluded on purpose: a tool's failure text becomes
     * `ToolResult` content and is model input. `app/Console` is excluded
     * because CLI output is permanently English.
     *
     * @var list<string>
     */
    private const THROWN_MESSAGE_DIRECTORIES = [
        'Services', 'Http', 'Core', 'Auth', 'Agents', 'Models', 'Plugins', 'Recipes',
    ];

    /**
     * Messages that are not operator-facing even though they are thrown.
     *
     * A hand-kept list is what this design refuses elsewhere, and rightly —
     * but these are exceptions to the rule rather than the rule itself: the
     * string never reaches an envelope, so translating it would be
     * translating a message nobody can see.
     *
     * @var list<string>
     */
    private const NOT_OPERATOR_FACING = [
        'Access tool settings via ToolConfigService, not directly.',
        'BASE_PATH is not defined. Add `define(\'BASE_PATH\', dirname(__FILE__, 2));` to your bin/spora before requiring this script.',
        'PrincipalService not wired into AgentPrincipalService — cannot transfer.',
    ];

    public function __construct(
        private readonly SourceLocator $source,
        private readonly TokenStream $tokens,
    ) {}

    /**
     * @return list<array{message: string, locator: string}>
     */
    public function harvest(): array
    {
        $found = [];

        foreach (self::THROWN_MESSAGE_DIRECTORIES as $directory) {
            $path = $this->source->appDir() . '/' . $directory;

            foreach ($this->source->phpFilesIn($path) as $file) {
                foreach ($this->thrownMessageLiteralsIn((string) file_get_contents($file)) as $line => $message) {
                    if (in_array($message, self::NOT_OPERATOR_FACING, true)) {
                        continue;
                    }
                    $found[] = ['message' => $message, 'locator' => $this->source->relative($file) . ':' . $line];
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int, string> line => message
     */
    private function thrownMessageLiteralsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $found = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_THROW) {
                continue;
            }

            // `throw new` — a thrown object is built with `new`, so the
            // literal follows the class name and its constructor argument.
            $cursor = $this->newExpressionAt($tokens, $i);
            if ($cursor === null) {
                continue;
            }

            $argument = $this->firstStringArgument($tokens, $cursor);
            if ($argument !== null) {
                $found[$argument[1]] = $argument[0];
            }
        }

        return $found;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function newExpressionAt(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_NEW) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: string, 1: int}|null the decoded literal and its line
     */
    private function firstStringArgument(array $tokens, int $from): ?array
    {
        $open = $this->openingParen($tokens, $from);
        if ($open === null) {
            return null;
        }

        $literal = $this->argumentLiteral($tokens, $open);
        if ($literal === null) {
            return null;
        }

        return [$this->tokens->decode($literal[1]), $literal[2]];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function openingParen(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) && $token === '(') {
                return $i;
            }
        }

        return null;
    }

    /**
     * The literal that is the whole first argument of the call.
     *
     * A concatenation (`'a' . $b`) is not a whole message — only a
     * literal that ends the argument is one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return ?array{0: int, 1: string, 2: int}
     */
    private function argumentLiteral(array $tokens, int $open): ?array
    {
        $cursor = $this->tokens->nextSignificantIndex($tokens, $open + 1);
        $literal = $tokens[$cursor] ?? null;
        if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $after = $tokens[$this->tokens->nextSignificantIndex($tokens, $cursor + 1)] ?? null;
        // `)` and `,` are single-character tokens, not array tokens.
        if ($after !== ')' && $after !== ',') {
            return null;
        }

        return $literal;
    }
}
