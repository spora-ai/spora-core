<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use FilesystemIterator;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolsRecommendsSkillsValidator;
use Spora\Skills\SkillScanner;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

// Test-only tool classes declared inline so the recommendsSkills variations
// stay co-located with the assertions reading them. Each implements
// ToolInterface with a no-op execute — we only instantiate the attribute,
// never run execute(); the validator uses reflection, not execution.

#[Tool(name: 'rec_empty', description: 'No recommended skills.')]
final class RecEmptyTool implements ToolInterface
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return '';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}

#[Tool(name: 'rec_all_present', description: 'All slugs present.', recommendsSkills: ['time-arithmetic'])]
final class RecAllPresentTool implements ToolInterface
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return '';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}

#[Tool(name: 'rec_one_missing', description: 'One slug missing.', recommendsSkills: ['does-not-exist'])]
final class RecOneMissingTool implements ToolInterface
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return '';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}

#[Tool(name: 'rec_mixed', description: 'Mixed presence.', recommendsSkills: ['time-arithmetic', 'does-not-exist'])]
final class RecMixedTool implements ToolInterface
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return '';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}

/**
 * Build a SkillScanner rooted at a fresh temp directory and write a
 * `SKILL.md` for each requested slug. Returns [scanner, cleanup]. The
 * cleanup callback recursively removes the temp root.
 *
 * @param list<string> $slugs
 * @return array{0: SkillScanner, 1: callable(): void}
 */
function buildScannerWithSlugs(array $slugs): array
{
    $root = sys_get_temp_dir() . '/spora_recs_' . uniqid('', true);
    if (!mkdir($root, 0o755, true) && !is_dir($root)) {
        throw new RuntimeException("Cannot create scanner root: {$root}");
    }

    foreach ($slugs as $slug) {
        $dir = $root . '/' . $slug;
        if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create skill dir: {$dir}");
        }
        file_put_contents(
            $dir . '/SKILL.md',
            "---\nname: {$slug}\ndescription: Test skill {$slug}.\n---\n\n# Body\n",
        );
    }

    $scanner = new SkillScanner([
        ['path' => $root, 'source' => 'project'],
    ]);

    $cleanup = static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $real = $f->getRealPath();
            if ($real === false) {
                continue;
            }
            @is_dir($real) ? @rmdir($real) : @unlink($real);
        }
        @rmdir($root);
    };

    return [$scanner, $cleanup];
}

/**
 * Wire a validator + cleanup for the given tool classes and on-disk slugs.
 *
 * @param list<string> $toolClasses
 * @param list<string> $onDiskSlugs
 * @return array{0: ToolsRecommendsSkillsValidator, 1: callable(): void}
 */
function makeValidatorFixture(array $toolClasses, array $onDiskSlugs): array
{
    [$scanner, $scannerCleanup] = buildScannerWithSlugs($onDiskSlugs);
    $resolver = new ToolConfigNameResolver(new NullLogger(), $toolClasses);
    $validator = new ToolsRecommendsSkillsValidator($resolver, $scanner);

    return [$validator, $scannerCleanup];
}

test('validate returns [] when the tool declares no recommendsSkills', function (): void {
    [$validator, $cleanup] = makeValidatorFixture([RecEmptyTool::class], []);
    try {
        expect($validator->validate())->toBe([]);
    } finally {
        $cleanup();
    }
});

test('validate returns [] when every declared slug is on disk', function (): void {
    [$validator, $cleanup] = makeValidatorFixture(
        [RecAllPresentTool::class],
        ['time-arithmetic'],
    );
    try {
        expect($validator->validate())->toBe([]);
    } finally {
        $cleanup();
    }
});

test('validate reports a violation when a single slug is missing', function (): void {
    [$validator, $cleanup] = makeValidatorFixture(
        [RecOneMissingTool::class],
        [],
    );
    try {
        $violations = $validator->validate();
        expect($violations)->toHaveCount(1);
        expect($violations[0]['tool_class'])->toBe(RecOneMissingTool::class)
            ->and($violations[0]['tool_name'])->toBe('rec_one_missing')
            ->and($violations[0]['missing'])->toBe(['does-not-exist']);
    } finally {
        $cleanup();
    }
});

test('validate reports only the missing slug from a mixed list', function (): void {
    [$validator, $cleanup] = makeValidatorFixture(
        [RecMixedTool::class],
        ['time-arithmetic'],
    );
    try {
        $violations = $validator->validate();
        expect($violations)->toHaveCount(1);
        expect($violations[0]['tool_class'])->toBe(RecMixedTool::class)
            ->and($violations[0]['missing'])->toBe(['does-not-exist']);
    } finally {
        $cleanup();
    }
});

test('validate is case-insensitive: a lowercased attribute slug resolves to an uppercase on-disk dir', function (): void {
    // The attribute regex only accepts lowercase slugs, so the slug-side
    // input is always lowercase. The on-disk directory may use uppercase
    // (the validator must still match — the Skill frontmatter validator
    // would warn on it, but the Skill object still surfaces with its
    // raw dir basename). We assert the validator's lowercased comparison
    // is the matching rule.
    [$validator, $cleanup] = makeValidatorFixture(
        [RecAllPresentTool::class],
        ['Time-Arithmetic'],
    );
    try {
        expect($validator->validate())->toBe([]);
    } finally {
        $cleanup();
    }
});

test('validate returns [] when the scanner is null', function (): void {
    // When the container resolves without a SkillScanner bound (build/test
    // contexts), the validator must stay silent — same trade-off as the
    // controller's nullable validator parameter.
    $resolver = new ToolConfigNameResolver(new NullLogger(), [RecOneMissingTool::class]);
    $validator = new ToolsRecommendsSkillsValidator($resolver, null);

    expect($validator->validate())->toBe([]);
});

test('validate returns [] when the registered class has no #[Tool] attribute', function (): void {
    $classWithoutTool = new class implements ToolInterface {
        public function execute(
            array $arguments,
            int $agentId,
            ?int $userId = null,
            ?int $taskId = null,
            ?\Spora\Services\PrincipalContext $context = null,
        ): ToolResult {
            return new ToolResult(true, 'ok');
        }

        public function describeAction(array $arguments): string
        {
            return '';
        }

        public function getParametersSchema(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }
    };

    [$scanner, $cleanup] = buildScannerWithSlugs([]);
    try {
        $resolver = new ToolConfigNameResolver(new NullLogger(), [$classWithoutTool::class]);
        $validator = new ToolsRecommendsSkillsValidator($resolver, $scanner);
        expect($validator->validate())->toBe([]);
    } finally {
        $cleanup();
    }
});
