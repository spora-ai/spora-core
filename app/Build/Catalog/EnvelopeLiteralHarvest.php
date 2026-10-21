<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

/**
 * Every string literal that reaches an HTTP error envelope.
 *
 * Found syntactically so the locator is the real line rather than a
 * reconstructed one.
 *
 * Error-message positions this harvest recognises:
 *
 *  - `'message' => '…'` is the envelope itself.
 *  - The four helper names build the same envelope one frame up, which is
 *    where most of core's copy actually lives. Harvesting only the literal
 *    envelope would leave the majority of the operator's error text out of
 *    the catalogue and, since `i18n:check` fails on any gap, permanently
 *    untranslatable.
 */
final class EnvelopeLiteralHarvest implements Harvest
{
    /**
     * The helper names that build an error envelope.
     *
     * @var list<string>
     */
    private const ERROR_MESSAGE_HELPERS = ['error', 'notFound', 'forbidden', 'unprocessable'];

    /**
     * Where each helper's `$message` parameter sits when the helper is
     * the trait's own.
     *
     * @var array<string, int>
     */
    private const TRAIT_PARAMETER_INDEXES = [
        'error' => 1,
        'notFound' => 1,
        'forbidden' => 1,
        'unprocessable' => 1,
    ];

    private readonly ClassConstantReader $constants;

    public function __construct(
        private readonly SourceLocator $source,
        private readonly TokenStream $tokens,
    ) {
        $this->constants = new ClassConstantReader($tokens);
    }

    /**
     * @return list<array{message: string, locator: string}>
     */
    public function harvest(): array
    {
        $found = [];

        foreach ($this->files() as $file) {
            if (!is_file($file)) {
                continue;
            }

            foreach ($this->errorMessageLiteralsIn((string) file_get_contents($file)) as $line => $message) {
                $found[] = ['message' => $message, 'locator' => $this->source->relative($file) . ':' . $line];
            }
        }

        return $found;
    }

    /**
     * The files whose error envelopes reach an operator.
     *
     * @return list<string>
     */
    private function files(): array
    {
        $appDir = $this->source->appDir();

        $files = [
            $appDir . '/Core/Kernel.php',
            // The router builds the not-found and method-not-allowed
            // envelopes itself, so its copy is in the catalogue like any
            // other operator-facing message.
            $appDir . '/Core/Router.php',
        ];

        foreach ($this->source->phpFilesIn($appDir) as $path) {
            if (str_contains($path, '/Http/')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * The envelope messages of one file, by the line they are written on.
     *
     * @return array<int, string> line => message
     */
    private function errorMessageLiteralsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $constants = $this->constants->constants($tokens);
        $messageIndex = $this->messageParameterIndexes($tokens);

        $found = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            $literal = $this->errorLiteralAt($tokens, $i, $token, $constants, $messageIndex);
            if ($literal !== null) {
                $found[$literal[1]] = $literal[0];
            }
        }

        return $found;
    }

    /**
     * The envelope message at `$index`, whichever of the two shapes it has.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{0: int, 1: string, 2: int}               $token
     * @param array<string, string>                           $constants
     * @param array<string, int>                              $messageIndex
     * @return ?array{0: string, 1: int} message, line
     */
    private function errorLiteralAt(array $tokens, int $index, array $token, array $constants, array $messageIndex): ?array
    {
        if ($this->startsHelperCall($tokens, $index)) {
            return $this->stringArgument($tokens, $index, $messageIndex[$token[1]]);
        }

        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || trim($token[1], "'\"") !== 'message') {
            return null;
        }

        return $this->messageAfterKey($tokens, $index, $constants);
    }

    /**
     * The message on the right of a `'message' =>` key.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                           $constants
     * @return ?array{0: string, 1: int} message, line
     */
    private function messageAfterKey(array $tokens, int $index, array $constants): ?array
    {
        // The arrow and the value are separated by whitespace, which
        // `token_get_all` reports as its own token — so the arrow is
        // not at a fixed offset from the key.
        $arrowIndex = $this->tokens->nextSignificantIndex($tokens, $index + 1);
        $arrow = $tokens[$arrowIndex] ?? null;
        if (!is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW) {
            return null;
        }

        return $this->stringAfterArrow($tokens, $this->tokens->nextSignificantIndex($tokens, $arrowIndex + 1), $constants);
    }

    /**
     * True when token `$i` is the method name of `$this->error(` /
     * `->notFound(` / `->forbidden(` / `->unprocessable(`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function startsHelperCall(array $tokens, int $index): bool
    {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], self::ERROR_MESSAGE_HELPERS, true)) {
            return false;
        }

        if (($tokens[$index + 1] ?? null) !== '(') {
            return false;
        }

        return $this->isThisCall($tokens, $index);
    }

    /**
     * Whether the call at `$index` is `$this->helper(`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isThisCall(array $tokens, int $index): bool
    {
        $before = $this->tokens->precedingSignificant($tokens, $index, 2);
        if (count($before) < 2) {
            return false;
        }

        [$arrow, $receiver] = $before;

        return is_array($arrow)
            && $arrow[0] === T_OBJECT_OPERATOR
            && is_array($receiver)
            && $receiver[0] === T_VARIABLE
            && $receiver[1] === '$this';
    }

    /**
     * One argument of the helper call starting at `$index`, when it is a
     * bare string literal or a `sprintf(...)` whose format string is the
     * message.
     *
     * A concatenation is not: `'a' . $b` has no whole message to key on.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return ?array{0: string, 1: int} message, line
     */
    private function stringArgument(array $tokens, int $index, int $position): ?array
    {
        if ($position < 0) {
            return null;
        }

        $argument = $this->callArguments($tokens, $index)[$position] ?? null;
        if ($argument === null) {
            return null;
        }

        return $this->literalArgument($argument);
    }

    /**
     * The arguments of the call whose opening paren is at `$index + 1`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private function callArguments(array $tokens, int $index): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;

        // Start past the opening paren so the commas separating this
        // call's own arguments sit at depth 0.
        for ($i = $index + 2, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($this->tokens->isInsignificant($token)) {
                continue;
            }

            if ($token === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];
                continue;
            }

            if ($token === ')' && $depth === 0) {
                $arguments[] = $current;
                break;
            }

            $depth += $this->depthChange($token);
            $current[] = $token;
        }

        return $arguments;
    }

    /**
     * How much a token changes the bracket nesting.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private function depthChange(array|string $token): int
    {
        if ($token === '(' || $token === '[' || $token === '{') {
            return 1;
        }

        if ($token === ')' || $token === ']' || $token === '}') {
            return -1;
        }

        return 0;
    }

    /**
     * The string literal an argument contributes, if it has one.
     *
     * A bare literal is one. So is `sprintf('format %s', …)` — the format
     * string *is* the message, with placeholders the translation keeps. A
     * concatenation is not: `'a' . $b` has no whole message to key on.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     * @return ?array{0: string, 1: int} message, line
     */
    private function literalArgument(array $argument): ?array
    {
        $significant = $this->significantTokens($argument);
        $first = $significant[0] ?? null;

        if (is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [$this->tokens->decode($first[1]), $first[2]];
        }

        $format = $this->sprintfFormatArgument($significant);

        return $format === null ? null : [$this->tokens->decode($format[1]), $format[2]];
    }

    /**
     * The tokens of one argument that are not whitespace or comments.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function significantTokens(array $argument): array
    {
        $significant = [];

        foreach ($argument as $token) {
            if ($this->tokens->isInsignificant($token)) {
                continue;
            }
            $significant[] = $token;
        }

        return $significant;
    }

    /**
     * The format string of a `sprintf(` argument, if the argument is one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant
     * @return ?array{0: int, 1: string, 2: int}
     */
    private function sprintfFormatArgument(array $significant): ?array
    {
        $first = $significant[0] ?? null;
        if (!is_array($first) || $first[0] !== T_STRING || strtolower($first[1]) !== 'sprintf') {
            return null;
        }

        $open = $significant[1] ?? null;
        $format = $significant[2] ?? null;
        if ($open !== '(' || !is_array($format) || $format[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return $format;
    }

    /**
     * The literal on the right of a `'message' =>`, whether it is written
     * inline or reached through a class constant in the same file.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                           $constants
     * @return ?array{0: string, 1: int} message, line
     */
    private function stringAfterArrow(array $tokens, int $index, array $constants): ?array
    {
        $value = $tokens[$index] ?? null;
        if (!is_array($value)) {
            return null;
        }

        if ($value[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [$this->tokens->decode($value[1]), $value[2]];
        }

        return $this->sprintfLiteral($tokens, $index, $value)
            ?? $this->resolvedConstant($tokens, $index, $value, $constants);
    }

    /**
     * The format string of a `sprintf(` in the envelope position: the
     * format string is the message, with placeholders the translation
     * keeps.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{0: int, 1: string, 2: int}               $value
     * @return ?array{0: string, 1: int} message, line
     */
    private function sprintfLiteral(array $tokens, int $index, array $value): ?array
    {
        if ($value[0] !== T_STRING || strtolower($value[1]) !== 'sprintf') {
            return null;
        }

        $literal = $this->tokens->sprintfFormat($tokens, $index);
        if ($literal === null) {
            return null;
        }

        return [$this->tokens->decode($literal[1]), $literal[2]];
    }

    /**
     * The literal a `self::ERR_X` / `JsonControllerHelpers::MSG_X` refers
     * to, resolved in the file that declares it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{0: int, 1: string, 2: int}               $value
     * @param array<string, string>                          $constants
     * @return ?array{0: string, 1: int} message, line
     */
    private function resolvedConstant(array $tokens, int $index, array $value, array $constants): ?array
    {
        if ($value[0] !== T_STRING || ($tokens[$index + 1][0] ?? null) !== T_DOUBLE_COLON) {
            return null;
        }

        $name = $tokens[$index + 2] ?? null;
        if (!is_array($name) || $name[0] !== T_STRING || !isset($constants[$name[1]])) {
            return null;
        }

        return [$constants[$name[1]], $value[2]];
    }

    /**
     * Where each error helper's `$message` parameter sits in its own
     * signature.
     *
     * The position cannot be assumed. `JsonControllerHelpers::error()` is
     * `(code, message, status)`, but a controller is free to declare its
     * own — and core does, in all three shapes: `error(string $message)`,
     * `error(code, message, status)` and `error(status, code, message)`.
     * Reading the parameter *name* is what makes the rule work on all of
     * them; a controller that never declares one gets the trait's shape.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, int>
     */
    private function messageParameterIndexes(array $tokens): array
    {
        $indexes = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = $this->tokens->nextSignificant($tokens, $i + 1);
            if (!is_array($name) || $name[0] !== T_STRING || !in_array($name[1], self::ERROR_MESSAGE_HELPERS, true)) {
                continue;
            }

            $position = array_search('$message', $this->parameterNames($tokens, $i), true);
            $indexes[$name[1]] = $position === false ? -1 : $position;
        }

        return array_merge(self::TRAIT_PARAMETER_INDEXES, $indexes);
    }

    /**
     * The parameter names of the function whose `function` keyword is at
     * `$functionIndex`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private function parameterNames(array $tokens, int $functionIndex): array
    {
        $names = [];
        $depth = 0;

        for ($i = $functionIndex + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth !== 1 || $this->tokens->isInsignificant($token)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $names[] = $token[1];
            }
        }

        return $names;
    }
}
