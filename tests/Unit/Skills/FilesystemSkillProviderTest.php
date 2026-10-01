<?php

declare(strict_types=1);

use Spora\Skills\Providers\FilesystemSkillProvider;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillScanner;

/**
 * The filesystem provider owns the read path that used to live in `SkillTool`:
 * scanned-listing membership, realpath containment, and the size cap. Those
 * checks only make sense here, where the "directory" assumption actually holds
 * — a database-backed provider has no equivalent and must bring its own.
 *
 * The tests that matter most are the ones a caller cannot replicate: the
 * symlink case, the cap, and the memo.
 */
function fsProviderFixture(): array
{
    $root = sys_get_temp_dir() . '/spora_fs_provider_' . uniqid('', true);
    mkdir($root, 0o755, true);
    $skillDir = $root . '/demo';
    mkdir($skillDir, 0o755, true);
    file_put_contents(
        $skillDir . '/SKILL.md',
        "---\nname: demo\ndescription: A demo skill\nlicense: MIT\n---\n\n# Demo\n\nBody text.\n",
    );
    file_put_contents($skillDir . '/reference.md', 'sidecar contents');
    file_put_contents($skillDir . '/huge.md', str_repeat('x', SkillProviderInterface::MAX_FILE_BYTES + 1));

    $provider = new FilesystemSkillProvider(new SkillScanner([['path' => $root, 'source' => 'project']]));

    $cleanup = static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    };

    return [$provider, $root, $skillDir, $cleanup];
}

it('serves the entry file and its sidecars', function (): void {
    [$provider, , , $cleanup] = fsProviderFixture();

    try {
        $files = $provider->getSkillFiles('demo', null);
        $paths = array_map(static fn(array $f): string => $f['path'], $files ?? []);

        expect($paths)->toContain('SKILL.md')
            ->and($paths)->toContain('reference.md')
            ->and($provider->getSkillFile('demo', 'reference.md', null))->toBe('sidecar contents')
            // The entry file resolves under its own name.
            ->and($provider->getSkillFile('demo', 'SKILL.md', null))->toContain('Body text.');
    } finally {
        $cleanup();
    }
});

it('ignores the principal', function (): void {
    // Shipped skills are operator-authored and identical for everyone; scoping
    // them per principal would mean one copy per user of a file the operator
    // already controls.
    [$provider, , , $cleanup] = fsProviderFixture();

    try {
        expect($provider->getSkills(1))->toHaveCount(1)
            ->and($provider->getSkills(999))->toHaveCount(1)
            ->and($provider->getSkills(null))->toHaveCount(1)
            ->and($provider->getSkillDetails('demo', 999))->not->toBeNull()
            ->and($provider->getSkillFile('demo', 'reference.md', null))->toBe('sidecar contents');
    } finally {
        $cleanup();
    }
});

it('returns null for an unknown skill and null for an unlisted path', function (): void {
    [$provider, , , $cleanup] = fsProviderFixture();

    try {
        expect($provider->getSkillDetails('nope', null))->toBeNull()
            ->and($provider->getSkillFiles('nope', null))->toBeNull()
            ->and($provider->getSkillFile('nope', 'SKILL.md', null))->toBeNull()
            ->and($provider->getSkillFile('demo', 'not-listed.md', null))->toBeNull();
    } finally {
        $cleanup();
    }
});

it('rejects traversal and absolute paths', function (): void {
    [$provider, $root, , $cleanup] = fsProviderFixture();

    try {
        // Written outside the skill directory so a successful read would be an
        // actual escape, not an empty file.
        file_put_contents($root . '/outside.txt', 'TOP SECRET');

        foreach (['../outside.txt', '../../etc/passwd', 'reference/../../../outside.txt', '/etc/passwd'] as $path) {
            expect($provider->getSkillFile('demo', $path, null))->toBeNull();
        }
    } finally {
        $cleanup();
    }
});

it('rejects a symlink planted inside the skill directory', function (): void {
    [$provider, $root, $skillDir, $cleanup] = fsProviderFixture();

    try {
        // SkillScanner::collectFiles() does not follow links, so the planted
        // target is absent from the scanned listing — which is precisely why
        // the listing check exists and cannot be replaced by a realpath test
        // alone. On a platform without symlink support there is nothing to
        // plant, so the case is vacuous rather than failing.
        $target = $root . '/outside.txt';
        file_put_contents($target, 'TOP SECRET');

        if (!@symlink($target, $skillDir . '/escape.md')) {
            $this->markTestSkipped('Symlinks unavailable on this filesystem.');
        }

        expect($provider->getSkillFiles('demo', null))->not->toContain('escape.md')
            ->and($provider->getSkillFile('demo', 'escape.md', null))->toBeNull();
    } finally {
        $cleanup();
    }
});

it('enforces the read cap before returning content', function (): void {
    [$provider, , , $cleanup] = fsProviderFixture();

    try {
        $files = array_map(
            static fn(array $f): string => $f['path'],
            $provider->getSkillFiles('demo', null) ?? [],
        );
        expect($files)->toContain('huge.md');

        // The listing still advertises the file — the cap is a read limit, not
        // a reason to hide that the file exists.
        expect($provider->getSkillFile('demo', 'huge.md', null))->toBeNull();
    } finally {
        $cleanup();
    }
});

it('sees a skill added on disk on the very next read', function (): void {
    [$provider, $root, , $cleanup] = fsProviderFixture();

    try {
        expect($provider->getSkills(null))->toHaveCount(1);

        $late = $root . '/late';
        mkdir($late, 0o755, true);
        file_put_contents($late . '/SKILL.md', "---\nname: late\ndescription: Added later\n---\n\nBody\n");

        // A worker holds this provider for its whole lifetime, so a memo would
        // make a skill invisible until the process restarted. Nothing calls an
        // invalidation hook, which is why there is no cache here at all.
        $names = array_map(static fn($s): string => $s->name, $provider->getSkills(null));
        expect($names)->toBe(['demo', 'late']);
    } finally {
        $cleanup();
    }
});

it('maps a skill to a summary and a descriptor', function (): void {
    [$provider, , , $cleanup] = fsProviderFixture();

    try {
        $summary = $provider->getSkills(null)[0];
        expect($summary->name)->toBe('demo')
            ->and($summary->description)->toBe('A demo skill')
            ->and($summary->license)->toBe('MIT')
            ->and($summary->source)->toBe('project')
            ->and($summary->fileCount)->toBe(3)
            ->and($summary->hasWarnings)->toBeFalse();

        $descriptor = $provider->getSkillDetails('demo', null);
        expect($descriptor)->not->toBeNull()
            ->and($descriptor->name())->toBe('demo')
            // The body has the frontmatter stripped — the model already sees
            // name + description through allowed_skills.
            ->and($descriptor->body)->not->toContain('license: MIT')
            ->and($descriptor->bodyBytes())->toBe(strlen($descriptor->body))
            ->and($descriptor->summary->fileCount)->toBe(3);
    } finally {
        $cleanup();
    }
});

it('carries scanner warnings onto the summary', function (): void {
    // A skill that fails validation is still surfaced, with the reason — the
    // operator has to be able to see why a bundled skill did not load.
    $root = sys_get_temp_dir() . '/spora_fs_provider_warn_' . uniqid('', true);
    mkdir($root . '/broken', 0o755, true);
    file_put_contents($root . '/broken/SKILL.md', "no frontmatter here\n");

    try {
        $provider = new FilesystemSkillProvider(new SkillScanner([['path' => $root, 'source' => 'core']]));
        $summaries = $provider->getSkills(null);

        expect($summaries)->toHaveCount(1)
            ->and($summaries[0]->hasWarnings)->toBeTrue();
    } finally {
        foreach (glob($root . '/broken/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($root . '/broken');
        @rmdir($root);
    }
});
