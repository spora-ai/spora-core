<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolsRecommendsSkillsValidator;
use Spora\Skills\Providers\FilesystemSkillProvider;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillScanner;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Declares `git` so the strict-mode `recommendsSkills` check can be exercised
 * against a provider whose `git` slug is served by a *malformed* skill — the
 * shape a bundling accident actually takes.
 */
#[Tool(name: 'fs_index_git_tool', description: 'Declares the git skill.', recommendsSkills: ['git'])]
final class FsProviderIndexGitTool implements ToolInterface
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?Spora\Services\PrincipalContext $context = null,
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
 * A provider over throwaway scan roots, given as `source label => [slug =>
 * SKILL.md contents]` in precedence order. Returns [provider, cleanup].
 *
 * @param array<string, array<string, string>> $roots
 * @return array{0: FilesystemSkillProvider, 1: callable(): void}
 */
function fsProviderOver(array $roots): array
{
    $base = sys_get_temp_dir() . '/spora_fs_over_' . uniqid('', true);
    $scannerRoots = [];
    $created = [];

    foreach ($roots as $source => $skills) {
        $root = $base . '/' . $source;
        mkdir($root, 0o755, true);
        $scannerRoots[] = ['path' => $root, 'source' => $source];

        foreach ($skills as $slug => $contents) {
            $dir = $root . '/' . $slug;
            mkdir($dir, 0o755, true);
            file_put_contents($dir . '/SKILL.md', $contents);
            $created[] = $dir;
        }
    }

    $cleanup = static function () use ($base): void {
        if (!is_dir($base)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($base);
    };

    return [new FilesystemSkillProvider(new SkillScanner($scannerRoots)), $cleanup];
}

/** A SKILL.md body that parses but fails validation. */
function fsBrokenSkillMd(): string
{
    return "no frontmatter here\n";
}

/**
 * The filesystem provider owns the read path that used to live in `SkillTool`:
 * scanned-listing membership, realpath containment, and the size cap. Those
 * checks only make sense here, where the "directory" assumption actually holds
 * — a database-backed provider has no equivalent and must bring its own.
 *
 * The tests that matter most are the ones a caller cannot replicate: the
 * symlink case, the cap, the memo, and the index identity below — the dedup
 * key that decides whether a broken skill is reported or swallowed.
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

it('carries the declared tool list onto both the summary and the detail', function (): void {
    $root = sys_get_temp_dir() . '/spora_fs_provider_tools_' . uniqid('', true);
    mkdir($root . '/demo', 0o755, true);
    file_put_contents(
        $root . '/demo/SKILL.md',
        "---\nname: demo\ndescription: A demo skill\nallowed-tools: \"read_url  agent\\nread_url\"\n---\n\n# Demo\n",
    );

    try {
        $provider = new FilesystemSkillProvider(new SkillScanner([['path' => $root, 'source' => 'project']]));

        // Both shapes: the summary is what a list fetch carries, the descriptor
        // what a detail read does.
        expect($provider->getSkills(null)[0]->requiredTools)->toBe(['read_url', 'agent'])
            ->and($provider->getSkillDetails('demo', null)->requiredTools)->toBe(['read_url', 'agent'])
            ->and($provider->getSkillDetails('demo', null)->allowedTools)
                ->toBe("read_url  agent\nread_url");
    } finally {
        @unlink($root . '/demo/SKILL.md');
        @rmdir($root . '/demo');
        @rmdir($root);
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

it('gives every broken skill its own row instead of collapsing them into one', function (): void {
    // A skill that fails to parse has no frontmatter `name` at all, so the index
    // used to put all three under one key and report a single row. The operator
    // then saw one failure for three broken bundles — SkillScanner promises each
    // one is reported, and this is where that promise was being kept.
    [$provider, $cleanup] = fsProviderOver([
        'core' => [
            'aaa-broken' => fsBrokenSkillMd(),
            'mmm-broken' => fsBrokenSkillMd(),
            'zzz-broken' => fsBrokenSkillMd(),
        ],
    ]);

    try {
        $summaries = $provider->getSkills(null);

        expect($summaries)->toHaveCount(3)
            ->and(array_map(static fn($s): ?string => $s->slug, $summaries))
                ->toBe(['aaa-broken', 'mmm-broken', 'zzz-broken']);

        foreach ($summaries as $summary) {
            expect($summary->hasWarnings)->toBeTrue();
        }
    } finally {
        $cleanup();
    }
});

it('keeps each broken skill distinguishable by the directory it came from', function (): void {
    // The collision the nameless-key fold causes: the surviving row's slug was
    // whichever root sorted first, so which broken bundle got reported depended
    // on a directory name.
    [$provider, $cleanup] = fsProviderOver([
        'core' => ['aaa-broken' => fsBrokenSkillMd()],
        'spora-plugin-late' => ['zzz-broken' => fsBrokenSkillMd()],
    ]);

    try {
        expect(array_map(
            static fn($s): array => [$s->slug, $s->source],
            $provider->getSkills(null),
        ))->toBe([
            ['aaa-broken', 'core'],
            ['zzz-broken', 'spora-plugin-late'],
        ]);
    } finally {
        $cleanup();
    }
});

it('keys a name-dir-mismatched skill on its directory, so it is listed but not readable by the bad name', function (): void {
    // A `name` that disagrees with its directory is not a handle anything else
    // agrees on: the strict-mode check resolves `slug`, the picker sends `name`,
    // the route is `:slug`. The directory is the one stable handle left, so the
    // skill keeps its row — the operator still sees the NAME_DIR_MISMATCH error —
    // and stops answering to the name that is wrong.
    [$provider, $cleanup] = fsProviderOver([
        'core' => ['git' => "---\nname: gitting\ndescription: Mismatched\n---\n\nBody\n"],
    ]);

    try {
        $summaries = $provider->getSkills(null);

        expect($summaries)->toHaveCount(1)
            ->and($summaries[0]->name)->toBe('gitting')
            ->and($summaries[0]->slug)->toBe('git')
            ->and($summaries[0]->hasWarnings)->toBeTrue()
            ->and($provider->getSkillDetails('gitting', null))->toBeNull()
            ->and($provider->getSkillDetails('git', null))->toBeNull();
    } finally {
        $cleanup();
    }
});

it('lets the earlier root win a same-slug collision across roots', function (): void {
    // Root precedence is the load-bearing part of the dedup key: a plugin that
    // ships its own `git` must not repoint an existing agent's allowed_skills
    // at different content.
    [$provider, $cleanup] = fsProviderOver([
        'core' => ['git' => "---\nname: git\ndescription: Core copy\n---\n\ncore body\n"],
        'spora-plugin-shadower' => ['git' => "---\nname: git\ndescription: Plugin copy\n---\n\nplugin body\n"],
    ]);

    try {
        $summaries = $provider->getSkills(null);

        expect($summaries)->toHaveCount(1)
            ->and($summaries[0]->source)->toBe('core')
            ->and($provider->getSkillDetails('git', null)?->body)->toContain('core body');
    } finally {
        $cleanup();
    }
});

it('keeps a same-slug collision resolvable for recommendsSkills when both copies are broken', function (): void {
    // Strict mode 500s `GET /api/v1/tools` for the whole instance when a
    // declared slug resolves to nothing. Two nameless skills used to collapse
    // into one row keyed by the empty name, so a *legitimate* `git` bundle that
    // happened to be malformed lost its slug and took the tool list down with
    // it.
    [$provider, $cleanup] = fsProviderOver([
        'core' => [
            'aaa-broken' => fsBrokenSkillMd(),
            'git' => fsBrokenSkillMd(),
        ],
    ]);

    try {
        $registry = new SkillProviderRegistry([$provider]);
        $validator = new ToolsRecommendsSkillsValidator(
            new ToolConfigNameResolver(new NullLogger(), [FsProviderIndexGitTool::class]),
            $registry,
        );

        expect($validator->validate())->toBe([]);
    } finally {
        $cleanup();
    }
});
