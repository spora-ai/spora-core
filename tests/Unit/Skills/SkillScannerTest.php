<?php

declare(strict_types=1);

use Spora\Skills\Skill;
use Spora\Skills\SkillScanner;

/**
 * Build a SkillScanner over a synthesised set of scan roots under sys_get_temp_dir.
 * Returns [scanner, cleanup, paths] — the caller must invoke cleanup() in a finally
 * block. The `paths` array holds the absolute scan roots so tests can write skills
 * into them.
 *
 * @param list<array{dir?: string, source?: string}> $roots
 * @return array{0: SkillScanner, 1: callable(): void, 2: list<string>}
 */
function makeSkillScanner(array $roots = []): array
{
    $created = [];
    $scannerRoots = [];
    $paths = [];

    if ($roots === []) {
        $roots = [['dir' => null, 'source' => 'project']];
    }

    foreach ($roots as $root) {
        $abs = $root['dir'] ?? sys_get_temp_dir() . '/spora_skill_scan_' . uniqid('', true);
        if (!str_starts_with($abs, sys_get_temp_dir())) {
            throw new RuntimeException("Scanner test root must be under sys_get_temp_dir, got: {$abs}");
        }
        if (!is_dir($abs) && !mkdir($abs, 0o755, true) && !is_dir($abs)) {
            throw new RuntimeException("Cannot create test directory: {$abs}");
        }
        $source = $root['source'] ?? 'project';
        $scannerRoots[] = ['path' => $abs, 'source' => $source];
        $paths[] = $abs;
        $created[] = $abs;
    }

    $cleanup = static function () use (&$created): void {
        $files = [];
        foreach ($created as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $f) {
                $files[] = $f->getRealPath();
            }
        }
        foreach ($files as $f) {
            @is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        foreach ($created as $root) {
            @rmdir($root);
        }
        $created = [];
    };

    return [new SkillScanner($scannerRoots), $cleanup, $paths];
}

/**
 * Materialise a skill directory with a SKILL.md and optional sidecar files.
 *
 * @param array<string, string> $sidecars  map of relative-path => contents
 */
function writeSkill(string $parent, string $slug, string $frontmatter, string $body, array $sidecars = []): void
{
    $dir = rtrim($parent, '/') . '/' . $slug;
    if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create skill directory: {$dir}");
    }

    $yaml = "---\n" . trim($frontmatter, "\n") . "\n---\n";
    $contents = $yaml . "\n" . ltrim($body, "\n");
    file_put_contents($dir . '/SKILL.md', $contents);

    foreach ($sidecars as $rel => $content) {
        $target = $dir . '/' . $rel;
        $parentDir = dirname($target);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0o755, true);
        }
        file_put_contents($target, $content);
    }
}

test('scan() returns a Skill for every valid SKILL.md', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        writeSkill($root, 'alpha', "name: alpha\ndescription: First skill.", '# Alpha');
        writeSkill($root, 'beta', "name: beta\ndescription: Second skill.", '# Beta');

        $skills = $scanner->scan();

        expect($skills)->toHaveCount(2);
        $names = array_map(static fn(Skill $s) => $s->name(), $skills);
        expect($names)->toContain('alpha', 'beta');
    } finally {
        $cleanup();
    }
});

test('scan() lists SKILL.md and sidecar files with relative paths', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        writeSkill(
            $root,
            'time-arithmetic',
            "name: time-arithmetic\ndescription: Time math.",
            "# Body",
            [
                'examples.md'     => "# Examples\n",
                'references/REF.md' => "# Reference\n",
            ],
        );

        $skills = $scanner->scan();
        expect($skills)->toHaveCount(1);
        $files = $skills[0]->files();
        $paths = array_column($files, 'path');
        expect($paths)->toContain('SKILL.md', 'examples.md', 'references/REF.md');
        $byPath = [];
        foreach ($files as $f) {
            $byPath[$f['path']] = $f['bytes'];
        }
        expect($byPath['examples.md'])->toBe(strlen("# Examples\n"));
    } finally {
        $cleanup();
    }
});

test('scan() strips frontmatter from the body', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        writeSkill($root, 'x', "name: x\ndescription: X.", "# Body only\n\nNo frontmatter here.");

        $skills = $scanner->scan();
        expect($skills[0]->body())->toBe("# Body only\n\nNo frontmatter here.")
            ->and($skills[0]->body())->not->toContain('name: x');
    } finally {
        $cleanup();
    }
});

test('scan() surfaces a SKILL_FRONTMATTER_MISSING warning for files without YAML frontmatter', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        writeSkill($root, 'bad', "name: bad\ndescription: Bad.", "# body");

        // Overwrite SKILL.md with content that has no frontmatter.
        file_put_contents($root . '/bad/SKILL.md', "Just some prose, no frontmatter here.");

        $skills = $scanner->scan();
        $codes = array_column($skills[0]->warnings(), 'code');
        expect($codes)->toContain('SKILL_FRONTMATTER_MISSING');
    } finally {
        $cleanup();
    }
});

test('scan() surfaces NAME_DIR_MISMATCH when frontmatter name does not match the directory', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        writeSkill($root, 'time-arithmetic', "name: time-math\ndescription: Wrong name.", "# body");

        $skills = $scanner->scan();
        $codes = array_column($skills[0]->warnings(), 'code');
        expect($codes)->toContain('NAME_DIR_MISMATCH');
    } finally {
        $cleanup();
    }
});

test('scan() rejects names with consecutive hyphens', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        writeSkill($root, 'foo--bar', "name: foo--bar\ndescription: Bad name.", "# body");

        $skills = $scanner->scan();
        $codes = array_column($skills[0]->warnings(), 'code');
        expect($codes)->toContain('NAME_CONSECUTIVE_HYPHEN');
    } finally {
        $cleanup();
    }
});

test('scan() rejects description over 1024 chars', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        $long = str_repeat('a', 1100);
        writeSkill($root, 'long', "name: long\ndescription: \"{$long}\"", "# body");

        $skills = $scanner->scan();
        $codes = array_column($skills[0]->warnings(), 'code');
        expect($codes)->toContain('DESCRIPTION_TOO_LONG');
    } finally {
        $cleanup();
    }
});

test('scan() emits SKILL_BODY_OVERSIZE warning for bodies over 500 lines', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        $body = str_repeat("line\n", 600);
        writeSkill($root, 'big', "name: big\ndescription: Big body.", $body);

        $skills = $scanner->scan();
        $codes = array_column($skills[0]->warnings(), 'code');
        expect($codes)->toContain('SKILL_BODY_OVERSIZE');
    } finally {
        $cleanup();
    }
});

test('scan() tags project-level skills with source "project"', function (): void {
    $projectRoot = sys_get_temp_dir() . '/spora_skill_project_' . uniqid('', true);
    $frameworkRoot = sys_get_temp_dir() . '/spora_skill_framework_' . uniqid('', true);
    [$scanner, $cleanup] = makeSkillScanner([
        ['dir' => $projectRoot,   'source' => 'project'],
        ['dir' => $frameworkRoot, 'source' => 'core'],
    ]);
    try {
        writeSkill($projectRoot, 'p1', "name: p1\ndescription: Project skill.", "# p");
        writeSkill($frameworkRoot, 'core1', "name: core1\ndescription: Framework skill.", "# c");

        $skills = $scanner->scan();
        $byName = [];
        foreach ($skills as $s) {
            $byName[$s->name()] = $s->source();
        }
        expect($byName['p1'])->toBe('project')
            ->and($byName['core1'])->toBe('core');
    } finally {
        $cleanup();
    }
});

test('scan() surfaces SKILL_NAME_CONFLICT when two roots ship the same skill at the same priority', function (): void {
    $rootA = sys_get_temp_dir() . '/spora_skill_conflict_a_' . uniqid('', true);
    $rootB = sys_get_temp_dir() . '/spora_skill_conflict_b_' . uniqid('', true);
    [$scanner, $cleanup] = makeSkillScanner([
        ['dir' => $rootA, 'source' => 'project'],
        ['dir' => $rootB, 'source' => 'project'],
    ]);
    try {
        writeSkill($rootA, 'shared', "name: shared\ndescription: First.", "# first");
        writeSkill($rootB, 'shared', "name: shared\ndescription: Second.", "# second");

        $skills = $scanner->scan();
        $codes = [];
        foreach ($skills as $s) {
            foreach ($s->warnings() as $w) {
                $codes[] = $w['code'];
            }
        }
        expect($codes)->toContain('SKILL_NAME_CONFLICT');
    } finally {
        $cleanup();
    }
});

test('scan() does NOT flag a conflict when the same skill ships from two different sources', function (): void {
    $rootA = sys_get_temp_dir() . '/spora_skill_share_a_' . uniqid('', true);
    $rootB = sys_get_temp_dir() . '/spora_skill_share_b_' . uniqid('', true);
    [$scanner, $cleanup] = makeSkillScanner([
        ['dir' => $rootA, 'source' => 'project'],
        ['dir' => $rootB, 'source' => 'core'],
    ]);
    try {
        writeSkill($rootA, 'shared', "name: shared\ndescription: Project copy.", "# first");
        writeSkill($rootB, 'shared', "name: shared\ndescription: Framework copy.", "# second");

        $skills = $scanner->scan();
        $codes = [];
        foreach ($skills as $s) {
            foreach ($s->warnings() as $w) {
                $codes[] = $w['code'];
            }
        }
        expect($codes)->not->toContain('SKILL_NAME_CONFLICT');
        expect(count($skills))->toBe(2);
    } finally {
        $cleanup();
    }
});

test('scan() with non-existent directory returns empty array', function (): void {
    $scanner = new SkillScanner([
        ['path' => '/tmp/spora_skill_does_not_exist_' . uniqid('', true), 'source' => 'project'],
    ]);
    expect($scanner->scan())->toBe([]);
});

test('scan() skips directories that do not contain a SKILL.md', function (): void {
    [$scanner, $cleanup, $paths] = makeSkillScanner();
    $root = $paths[0];
    try {
        // A directory without SKILL.md is ignored.
        mkdir($root . '/empty-skill', 0o755, true);
        writeSkill($root, 'present', "name: present\ndescription: Present.", "# body");

        $skills = $scanner->scan();
        $names = array_map(static fn(Skill $s) => $s->name(), $skills);
        expect($names)->toBe(['present']);
    } finally {
        $cleanup();
    }
});

test('scan() finds the bundled time-arithmetic skill via the framework path', function (): void {
    // The framework path is computed at boot via Paths::framework(); we
    // bypass it here and point the scanner directly at the framework's
    // `skills/` directory in the source tree. The bundled skill should
    // be present and well-formed.
    $frameworkSkills = BASE_PATH . '/skills';
    if (!is_dir($frameworkSkills)) {
        $this->markTestSkipped("Framework skills directory not present at {$frameworkSkills}.");
    }
    $scanner = new SkillScanner([
        ['path' => $frameworkSkills, 'source' => 'core'],
    ]);
    $skills = $scanner->scan();
    $names = array_map(static fn(Skill $s) => $s->name(), $skills);
    expect($names)->toContain('time-arithmetic');

    $time = null;
    foreach ($skills as $s) {
        if ($s->name() === 'time-arithmetic') {
            $time = $s;
            break;
        }
    }
    expect($time)->not->toBeNull();
    $files = array_column($time->files(), 'path');
    expect($files)->toContain('SKILL.md', 'examples.md');
    expect($time->body())->toContain('Time arithmetic');
    expect($time->source())->toBe('core');
    expect($time->warnings())->toBeEmpty();
});
