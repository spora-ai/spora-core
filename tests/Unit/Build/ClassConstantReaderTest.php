<?php

declare(strict_types=1);

use Spora\Build\Catalog\ClassConstantReader;
use Spora\Build\Catalog\TokenStream;

/**
 * The reader is what turns `self::MSG_X` back into the message an
 * operator reads, so the resolution is pinned here rather than only through
 * the harvest that uses it.
 */
describe('ClassConstantReader', function (): void {
    function reader(): ClassConstantReader
    {
        return new ClassConstantReader(new TokenStream());
    }

    /**
     * The tokens of one expression, without the whitespace between them.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    function significant(string $snippet): array
    {
        $stream = new TokenStream();
        $significant = [];

        foreach (token_get_all($snippet) as $token) {
            if ($stream->isInsignificant($token)) {
                continue;
            }
            // The opening tag is part of the snippet, not of the expression.
            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }
            $significant[] = $token;
        }

        return $significant;
    }

    it('reads the literal a class constant holds', function (): void {
        $tokens = token_get_all("<?php\nclass A {\n    private const MSG_X = 'No such group.';\n}\n");

        expect(reader()->constants($tokens))->toBe(['MSG_X' => 'No such group.']);
    });

    it('resolves a self reference to the literal and the line it is written on', function (): void {
        $tokens = token_get_all("<?php\nclass A {\n    private const MSG_X = 'No such group.';\n}\n");
        $constants = reader()->constants($tokens);

        // The line is the reference's own, which is where a reader of the
        // harvest output is pointed.
        $resolved = reader()->resolveSelfConstant(significant("<?php\n\nself::MSG_X"), $constants);

        expect($resolved)->not->toBeNull()
            ->and($resolved[0])->toBe('No such group.')
            ->and($resolved[1])->toBe(3);
    });

    it('refuses a reference to a constant the file does not declare', function (): void {
        $tokens = token_get_all("<?php\nclass A {\n    private const MSG_X = 'No such group.';\n}\n");
        $constants = reader()->constants($tokens);

        // A constant from another file is out of scope: the extractor reads
        // one file at a time on purpose.
        expect(reader()->resolveSelfConstant(significant('<?php self::MSG_Y'), $constants))->toBeNull();
    });

    it('refuses a reference that is not to this class', function (): void {
        $tokens = token_get_all("<?php\nclass A {\n    private const MSG_X = 'No such group.';\n}\n");
        $constants = reader()->constants($tokens);

        expect(reader()->resolveSelfConstant(significant('<?php Other::MSG_X'), $constants))->toBeNull();
    });
});
