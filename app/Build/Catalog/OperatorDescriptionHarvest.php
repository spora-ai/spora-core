<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

/**
 * The operator-facing copy a `describeAction()` implementation returns —
 * the *values* it returns, not the operation names it switches on.
 */
final class OperatorDescriptionHarvest implements Harvest
{
    /**
     * The method whose return value is the operator's approval prompt.
     *
     * `describeAction()` returns operator-facing copy: it is written to
     * `tool_calls.human_description` and shipped to the frontend, and the
     * model-facing history never reads it. So it translates — but the
     * *stored* value stays English and the translation happens at
     * serialization, in `ToolCallSerializer`.
     */
    private const OPERATOR_DESCRIPTION_METHOD = 'describeAction';

    /**
     * Directories whose `describeAction()` copy is operator-facing.
     *
     * `app/Tools` is where core's own tools live. A plugin's copy is
     * harvested by the plugin's own extractor.
     *
     * @var list<string>
     */
    private const OPERATOR_DESCRIPTION_DIRECTORIES = ['Tools'];

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

        foreach (self::OPERATOR_DESCRIPTION_DIRECTORIES as $directory) {
            $path = $this->source->appDir() . '/' . $directory;

            foreach ($this->source->phpFilesIn($path) as $file) {
                foreach ($this->operatorDescriptionLiteralsIn((string) file_get_contents($file)) as $line => $message) {
                    $found[] = ['message' => $message, 'locator' => $this->source->relative($file) . ':' . $line];
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int, string> line => message
     */
    private function operatorDescriptionLiteralsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $found = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = $this->methodNameAfter($tokens, $i);
            if ($name !== self::OPERATOR_DESCRIPTION_METHOD) {
                continue;
            }

            $body = $this->methodBody($tokens, $i);
            if ($body === null) {
                continue;
            }

            $slice = array_slice($tokens, $body[0], $body[1] - $body[0] + 1);
            foreach ($this->copyLiteralsIn($slice) as $line => $message) {
                $found[$line] = $message;
            }
        }

        return $found;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function methodNameAfter(array $tokens, int $index): ?string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($this->tokens->isInsignificant($token)) {
                continue;
            }
            if ($token[0] === T_STRING) {
                return $token[1];
            }
            return null;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: int}|null start and end index
     */
    private function methodBody(array $tokens, int $index): ?array
    {
        $depth = 0;
        $start = null;

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $character = is_array($token) ? null : $token;

            if ($character === '{') {
                if ($start === null) {
                    $start = $i;
                }
                $depth++;
                continue;
            }
            if ($character === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    return [$start, $i];
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<int, string> line => message
     */
    private function copyLiteralsIn(array $tokens): array
    {
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            $literal = $this->copyLiteralAt($tokens, $i, $token);
            if ($literal !== null) {
                $found[$literal[1]] = $literal[0];
            }
        }

        return $found;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{0: int, 1: string, 2: int}               $token
     * @return ?array{0: string, 1: int} message, line
     */
    private function copyLiteralAt(array $tokens, int $index, array $token): ?array
    {
        if ($token[0] === T_STRING) {
            return $this->sprintfCopy($tokens, $index, $token);
        }

        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || !$this->isCopyPosition($tokens, $index)) {
            return null;
        }

        return [$this->tokens->decode($token[1]), $token[2]];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{0: int, 1: string, 2: int}               $token
     * @return ?array{0: string, 1: int} message, line
     */
    private function sprintfCopy(array $tokens, int $index, array $token): ?array
    {
        if (strtolower($token[1]) !== 'sprintf') {
            return null;
        }

        $literal = $this->tokens->sprintfFormat($tokens, $index);
        if ($literal === null) {
            return null;
        }

        return [$this->tokens->decode($literal[1]), $literal[2]];
    }

    /**
     * Whether the literal at `$index` sits where copy is written.
     *
     * A `match` arm's *value* is copy; its condition is an operation name.
     * A whole `return 'copy';` is copy. The first fragment of a
     * concatenation is neither.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isCopyPosition(array $tokens, int $index): bool
    {
        if ($this->isFollowedByConcatenation($tokens, $index)) {
            return false;
        }

        return $this->isMatchArmValue($tokens, $index) || $this->isReturnValue($tokens, $index);
    }

    /**
     * Whether the literal at `$index` is the first part of a concatenation.
     *
     * `'Format epoch ' . $x . ' as datetime'` — the first fragment is not
     * a message on its own, so it is not harvested.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isFollowedByConcatenation(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($this->tokens->isInsignificant($token)) {
                continue;
            }
            return $token === '.';
        }

        return false;
    }

    /**
     * Whether the literal at `$index` is the *value* of a `match` arm.
     *
     * In `'condition' => 'copy'` the copy is the literal **after** the arrow;
     * the condition is the one before it, and is an operation name rather
     * than copy. So the test looks backwards for the arrow.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isMatchArmValue(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($this->tokens->isInsignificant($token)) {
                continue;
            }
            return is_array($token) && $token[0] === T_DOUBLE_ARROW;
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isReturnValue(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($this->tokens->isInsignificant($token)) {
                continue;
            }
            return is_array($token) && $token[0] === T_RETURN;
        }

        return false;
    }
}
