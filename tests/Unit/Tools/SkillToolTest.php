<?php

declare(strict_types=1);

use Spora\Skills\Skill;
use Spora\Skills\SkillScanner;
use Spora\Tools\SkillTool;

/**
 * Build a SkillTool backed by a real SkillScanner reading a temp dir,
 * with a mocked ToolConfigServiceInterface that returns the given
 * effective settings. Returns the tool and a cleanup callback.
 *
 * @return array{0: SkillTool, 1: callable(): void, 2: string}
 */
function makeSkillToolFixture(array $effectiveSettings = []): array
{
    $root = sys_get_temp_dir() . '/spora_skill_toy_' . uniqid('', true);
    mkdir($root, 0o755, true);

    $scanner = new SkillScanner([
        ['path' => $root, 'source' => 'project'],
    ]);

    $config = Mockery::mock(Spora\Services\ToolConfigServiceInterface::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($effectiveSettings);

    $tool = new SkillTool($scanner, $config);

    $cleanup = static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            @is_dir($f->getRealPath()) ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
        }
        @rmdir($root);
    };

    return [$tool, $cleanup, $root];
}

/**
 * Materialise a skill directory with a SKILL.md and optional sidecar files
 * inside the given scan root.
 *
 * @param array<string, string> $sidecars
 */
function writeToySkill(string $root, string $slug, string $body, array $sidecars = []): void
{
    $dir = $root . '/' . $slug;
    mkdir($dir, 0o755, true);
    file_put_contents(
        $dir . '/SKILL.md',
        "---\nname: {$slug}\ndescription: Test skill {$slug}.\n---\n\n" . ltrim($body, "\n"),
    );
    foreach ($sidecars as $rel => $content) {
        $target = $dir . '/' . $rel;
        $parentDir = dirname($target);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0o755, true);
        }
        file_put_contents($target, $content);
    }
}

test('SkillTool read of SKILL.md strips the frontmatter and returns the body', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        writeToySkill($root, 'git', "# Body\n\nInstructions here.");
        $result = $tool->execute(['action' => 'read', 'name' => 'git'], 1, 1);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toBe("# Body\n\nInstructions here.")
            ->and($result->content)->not->toContain('name: git')
            ->and($result->data['name'])->toBe('git')
            ->and($result->data['filename'])->toBe('SKILL.md');
    } finally {
        $cleanup();
    }
});

test('SkillTool read of a sidecar file returns the file contents verbatim', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        writeToySkill($root, 'git', '# Body', ['examples.md' => "# Examples\n"]);
        $result = $tool->execute(
            ['action' => 'read', 'name' => 'git', 'filename' => 'examples.md'],
            1,
            1,
        );

        expect($result->success)->toBeTrue()
            ->and($result->content)->toBe("# Examples\n")
            ->and($result->data['filename'])->toBe('examples.md');
    } finally {
        $cleanup();
    }
});

test('SkillTool rejects reads when the skill is not in allowed_skills', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['weather']]);
    try {
        writeToySkill($root, 'git', '# Body');
        $result = $tool->execute(['action' => 'read', 'name' => 'git'], 1, 1);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_skills list');
    } finally {
        $cleanup();
    }
});

test('SkillTool rejects reads when the skill does not exist on disk', function (): void {
    [$tool, $cleanup] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        $result = $tool->execute(['action' => 'read', 'name' => 'git'], 1, 1);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not currently available');
    } finally {
        $cleanup();
    }
});

test('SkillTool rejects path-traversal attempts in filename', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        writeToySkill($root, 'git', '# Body');
        $result = $tool->execute(
            ['action' => 'read', 'name' => 'git', 'filename' => '../../etc/passwd'],
            1,
            1,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Invalid filename');
    } finally {
        $cleanup();
    }
});

test('SkillTool rejects leading-slash filenames', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        writeToySkill($root, 'git', '# Body');
        $result = $tool->execute(
            ['action' => 'read', 'name' => 'git', 'filename' => '/etc/passwd'],
            1,
            1,
        );

        expect($result->success)->toBeFalse();
    } finally {
        $cleanup();
    }
});

test('SkillTool rejects filenames not in the listing', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        writeToySkill($root, 'git', '# Body');
        $result = $tool->execute(
            ['action' => 'read', 'name' => 'git', 'filename' => 'missing.md'],
            1,
            1,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not part of skill');
    } finally {
        $cleanup();
    }
});

test('SkillTool rejects files over the 50 KB hard cap', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        writeToySkill($root, 'git', '# Body', ['big.md' => str_repeat('a', 60_000)]);
        $result = $tool->execute(
            ['action' => 'read', 'name' => 'git', 'filename' => 'big.md'],
            1,
            1,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('60000 bytes');
    } finally {
        $cleanup();
    }
});

test('SkillTool files operation returns the file listing', function (): void {
    [$tool, $cleanup, $root] = makeSkillToolFixture(['allowed_skills' => ['git']]);
    try {
        writeToySkill($root, 'git', '# Body', ['examples.md' => "# Examples\n"]);
        $result = $tool->execute(['action' => 'files', 'name' => 'git'], 1, 1);

        expect($result->success)->toBeTrue()
            ->and($result->data['files'])->toHaveCount(2)
            ->and($result->content)->toContain('SKILL.md', 'examples.md');
    } finally {
        $cleanup();
    }
});

test('SkillTool describeAction describes the right operation', function (): void {
    [$tool, $cleanup] = makeSkillToolFixture();
    try {
        expect($tool->describeAction(['action' => 'read', 'name' => 'git']))
            ->toContain("Read a file from skill 'git'")
            ->and($tool->describeAction(['action' => 'files', 'name' => 'git']))
            ->toContain("List the files in skill 'git'");
    } finally {
        $cleanup();
    }
});

test('SkillTool has the read and files operations declared', function (): void {
    [$tool, $cleanup] = makeSkillToolFixture();
    try {
        $ops = array_map(static fn($op) => $op->name, $tool->getOperations());
        expect($ops)->toContain('read', 'files');
    } finally {
        $cleanup();
    }
});

test('SkillTool accepts the bundled time-arithmetic skill via the framework path', function (): void {
    // The framework's `skills/` directory lives in the source tree; this
    // assertion mirrors the SkillScannerTest integration check that the
    // scanner finds it, but additionally exercises the tool against a
    // real on-disk skill end-to-end.
    $frameworkSkills = BASE_PATH . '/skills';
    if (!is_dir($frameworkSkills)) {
        $this->markTestSkipped("Framework skills directory not present at {$frameworkSkills}.");
    }

    $scanner = new SkillScanner([
        ['path' => $frameworkSkills, 'source' => 'core'],
    ]);
    $config = Mockery::mock(Spora\Services\ToolConfigServiceInterface::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['allowed_skills' => ['time-arithmetic']]);

    $tool = new SkillTool($scanner, $config);
    $result = $tool->execute(['action' => 'read', 'name' => 'time-arithmetic'], 1, 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Time arithmetic')
        ->and($result->content)->not->toContain('name: time-arithmetic');
});
