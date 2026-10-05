<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Http\SkillController;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Skills\Providers\FilesystemSkillProvider;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillScanner;
use Symfony\Component\HttpFoundation\Request;
use Tests\Fixtures\Skills\StubSkillProvider;

defined('SKILL_CTRL_PASSWORD') || define('SKILL_CTRL_PASSWORD', 'Password1!');

/**
 * Shipped skills plus a principal-scoped provider standing in for a
 * user-authored-skills plugin. The controller's job here is to route the
 * caller's `?principal_id=` to the right provider and to refuse to confirm the
 * existence of a skill belonging to somebody else.
 */
function makeSkillControllerFixture(AuthService|Mockery\MockInterface|null $auth = null): array
{
    $root = sys_get_temp_dir() . '/spora_skill_ctrl_' . uniqid('', true);
    mkdir($root, 0o755, true);
    $scanner = new SkillScanner([
        ['path' => $root, 'source' => 'project'],
    ]);

    // `my-notes` belongs to the caller; `team-playbook` to somebody else, the
    // way a group-owned skill would be invisible to a personal principal.
    $ownPrincipal = createUserPrincipalPublic($GLOBALS['__skillCtrlUserId'] ?? 0);
    $custom = (new StubSkillProvider('custom-skills'))
        ->add('my-notes', ['SKILL.md'], 'My private notes.', null, $ownPrincipal)
        ->add('team-playbook', ['SKILL.md'], 'Team playbook.', null, $ownPrincipal + 1000);
    $custom->onlyVisibleTo = $ownPrincipal;

    $registry = new SkillProviderRegistry([
        new FilesystemSkillProvider($scanner),
        $custom,
    ]);

    $auth ??= Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn($GLOBALS['__skillCtrlUserId'] ?? null);

    $controller = new SkillController(
        $auth,
        $registry,
        new PrincipalService(new PrincipalResolver()),
    );

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

    return [$controller, $cleanup, $root, $ownPrincipal];
}

function writeToySkillMd(string $root, string $slug, string $body, string $description = 'Test skill.', ?string $allowedTools = null): void
{
    $dir = $root . '/' . $slug;
    mkdir($dir, 0o755, true);
    $frontmatter = "name: {$slug}\ndescription: {$description}\n";
    if ($allowedTools !== null) {
        // Quoted, so a folded/multi-line declaration survives as the string the
        // parser is meant to see.
        $frontmatter .= 'allowed-tools: ' . json_encode($allowedTools) . "\n";
    }
    file_put_contents(
        $dir . '/SKILL.md',
        "---\n" . $frontmatter . "---\n\n" . ltrim($body, "\n"),
    );
}

function skillCtrlUser(string $email = 'skillctrl@example.com'): int
{
    return bootAuth(bootAuthLayer(), $email, SKILL_CTRL_PASSWORD);
}

test('GET /skills returns summaries for every discovered skill', function (): void {
    $userId = skillCtrlUser();
    $GLOBALS['__skillCtrlUserId'] = $userId;
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.');
        writeToySkillMd($root, 'pdf', '# Body', 'PDF skill.');

        $response = $controller->index(Request::create('/api/v1/skills'));
        $payload = json_decode((string) $response->getContent(), true);

        expect($response->getStatusCode())->toBe(200)
            // Two shipped skills from the temp dir plus the one custom skill
            // the fixture's provider owns.
            ->and($payload['data']['skills'])->toHaveCount(3)
            ->and(array_column($payload['data']['skills'], 'name'))->toContain('git', 'pdf', 'my-notes')
            ->and($payload['data']['skills'][0]['description'])->toBeString();
    } finally {
        $cleanup();
    }
});

test('GET /skills requires authentication', function (): void {
    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn(null);
    [$controller, $cleanup] = makeSkillControllerFixture($auth);

    try {
        $response = $controller->index(Request::create('/api/v1/skills'));

        expect($response->getStatusCode())->toBe(401)
            ->and(json_decode((string) $response->getContent(), true)['error']['code'])
            ->toBe('UNAUTHENTICATED');
    } finally {
        $cleanup();
    }
});

test('GET /skills/{slug} returns the skill detail', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skilldetail@example.com');
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', "# Body\n\nSteps here.", 'Git skill.');

        $request = Request::create('/api/v1/skills/git');
        $request->attributes->set('slug', 'git');

        $response = $controller->show($request);
        $payload = json_decode((string) $response->getContent(), true);

        expect($response->getStatusCode())->toBe(200)
            ->and($payload['data']['skill']['name'])->toBe('git')
            ->and($payload['data']['skill']['body'])->toContain('Steps here.')
            ->and($payload['data']['skill']['description'])->toBe('Git skill.')
            ->and($payload['data']['source'])->toBe('project');
    } finally {
        $cleanup();
    }
});

test('GET /skills/{slug} returns 404 for an unknown skill', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skillmissing@example.com');
    [$controller, $cleanup] = makeSkillControllerFixture();
    try {
        $request = Request::create('/api/v1/skills/missing');
        $request->attributes->set('slug', 'missing');

        $response = $controller->show($request);
        $payload = json_decode((string) $response->getContent(), true);

        expect($response->getStatusCode())->toBe(404)
            ->and($payload['error']['code'])->toBe('SKILL_NOT_FOUND');
    } finally {
        $cleanup();
    }
});

test('GET /skills emits slug and the declared tool list', function (): void {
    // `slug` was computed and populated all along and dropped on the wire, so a
    // consumer had no stable identifier for a row it was rendering.
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skilltools@example.com');
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.', "read_url\nagent");

        $skills = json_decode((string) $controller->index(Request::create('/api/v1/skills'))->getContent(), true)['data']['skills'];
        $git = array_values(array_filter($skills, static fn(array $s): bool => $s['name'] === 'git'))[0];

        expect($git['slug'])->toBe('git')
            ->and($git['required_tools'])->toBe(['read_url', 'agent']);
    } finally {
        $cleanup();
    }
});

test('GET /skills/{slug} emits the raw and the parsed tool declaration', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skilldetailtools@example.com');
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.', 'read_url agent');

        $request = Request::create('/api/v1/skills/git');
        $request->attributes->set('slug', 'git');
        $skill = json_decode((string) $controller->show($request)->getContent(), true)['data']['skill'];

        // Raw stays for spec compatibility; the parsed list is what a consumer
        // can compare against the installed tools.
        expect($skill['allowed_tools'])->toBe('read_url agent')
            ->and($skill['required_tools'])->toBe(['read_url', 'agent']);
    } finally {
        $cleanup();
    }
});

test('GET /skills/{slug} emits an empty tool list for a skill that declares none', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skillnotools@example.com');
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.');

        $request = Request::create('/api/v1/skills/git');
        $request->attributes->set('slug', 'git');
        $skill = json_decode((string) $controller->show($request)->getContent(), true)['data']['skill'];

        expect($skill['allowed_tools'])->toBeNull()
            ->and($skill['required_tools'])->toBe([]);
    } finally {
        $cleanup();
    }
});

// ---------------------------------------------------------------------------
// principal_id scoping
// ---------------------------------------------------------------------------

test('GET /skills?principal_id=N lists only that principal\'s custom skills plus the shipped ones', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skillscope@example.com');
    [$controller, $cleanup, $root, $principalId] = makeSkillControllerFixture();

    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.');

        $response = $controller->index(
            Request::create('/api/v1/skills', 'GET', ['principal_id' => $principalId]),
        );
        $names = array_column(json_decode((string) $response->getContent(), true)['data']['skills'], 'name');

        expect($names)->toContain('git')
            ->and($names)->toContain('my-notes')
            ->and($names)->not->toContain('team-playbook');
    } finally {
        $cleanup();
    }
});

test('GET /skills without principal_id unions every principal the caller can see', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skillunion@example.com');
    [$controller, $cleanup, $root, $principalId] = makeSkillControllerFixture();

    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.');

        $names = array_column(
            json_decode((string) $controller->index(Request::create('/api/v1/skills'))->getContent(), true)['data']['skills'],
            'name',
        );

        expect($names)->toContain('git', 'my-notes');
    } finally {
        $cleanup();
    }
});

test('a principal-scoped skill another principal owns is a 404, not a 403', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skillcross@example.com');
    [$controller, $cleanup, , $ownPrincipal] = makeSkillControllerFixture();

    try {
        // Named a principal the caller cannot see. That id is discarded, so the
        // lookup falls back to the caller's own set — where `team-playbook` does
        // not exist, and answering 403 would confirm that it does somewhere.
        $request = Request::create('/api/v1/skills/team-playbook', 'GET', ['principal_id' => $ownPrincipal + 1000]);
        $request->attributes->set('slug', 'team-playbook');

        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(404)
            ->and(json_decode((string) $response->getContent(), true)['error']['code'])
            ->toBe('SKILL_NOT_FOUND');
    } finally {
        $cleanup();
    }
});

test('a principal_id the caller cannot see is discarded, not honoured', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skillidor@example.com');
    [$controller, $cleanup, , $ownPrincipal] = makeSkillControllerFixture();
    $foreign = $ownPrincipal + 1000;

    try {
        // `team-playbook` is owned by `$foreign`. A provider scopes by the id
        // it is handed and has no notion of the HTTP caller, so honouring this
        // parameter unchecked hands the caller another tenant's skill body.
        $show = Request::create('/api/v1/skills/team-playbook', 'GET', ['principal_id' => $foreign]);
        $show->attributes->set('slug', 'team-playbook');
        $detail = $controller->show($show);

        $listing = json_decode(
            (string) $controller->index(
                Request::create('/api/v1/skills', 'GET', ['principal_id' => $foreign]),
            )->getContent(),
            true,
        );
        $names = array_column($listing['data']['skills'], 'name');

        expect($detail->getStatusCode())->toBe(404)
            ->and(json_decode((string) $detail->getContent(), true)['error']['code'])->toBe('SKILL_NOT_FOUND')
            ->and($names)->not->toContain('team-playbook')
            // The caller's own skills are still served, so this is a narrowing
            // rejection and not an empty response.
            ->and($names)->toContain('my-notes');
    } finally {
        $cleanup();
    }
});

test('a malformed principal_id falls back to the caller union rather than 400ing', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skillbadparam@example.com');
    [$controller, $cleanup] = makeSkillControllerFixture();

    try {
        foreach (['', 'abc', '0', '-4'] as $raw) {
            $response = $controller->index(
                Request::create('/api/v1/skills', 'GET', ['principal_id' => $raw]),
            );
            expect($response->getStatusCode())->toBe(200);
        }
    } finally {
        $cleanup();
    }
});

test('the response envelope shape is unchanged', function (): void {
    $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('skallenvelope@example.com');
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.');
        $payload = json_decode((string) $controller->index(Request::create('/api/v1/skills'))->getContent(), true);

        expect($payload)->toHaveKey('data')
            ->and($payload['data'])->toHaveKey('skills')
            ->and($payload['data']['skills'][0])
            ->toHaveKeys(['name', 'description', 'source', 'license', 'files_count', 'has_warnings']);
    } finally {
        $cleanup();
    }
});

test('a caller with no principal row still sees the shipped skills', function (): void {
    // `visiblePrincipalIdsFor()` returns [] for a user whose principal row has
    // not been materialised, and both this listing and ⌘K loop over the visible
    // set — so an empty set returned an empty response, shipped skills included,
    // even though a shipped skill belongs to no principal and is visible to
    // everyone. The `allowed_skills` picker rendered blank.
    //
    // The fixture is built here rather than through makeSkillControllerFixture()
    // because that one calls createUserPrincipalPublic(), which would create the
    // very row this test needs absent.
    $userId = skillCtrlUser('skillctrlnoprin@example.com');
    $GLOBALS['__skillCtrlUserId'] = $userId;

    $root = sys_get_temp_dir() . '/spora_skill_noprin_' . uniqid('', true);
    mkdir($root, 0o755, true);
    writeToySkillMd($root, 'git', '# Body', 'Git skill.');

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn($userId);
    $controller = new SkillController(
        $auth,
        new SkillProviderRegistry([new FilesystemSkillProvider(new SkillScanner([['path' => $root, 'source' => 'project']]))]),
        new PrincipalService(new PrincipalResolver()),
    );

    try {
        // Precondition: the caller has no principal row yet.
        $precondition = Illuminate\Database\Capsule\Manager::table('principals')
            ->where('type', 'user')->where('user_id', $userId)->value('id');
        expect($precondition)->toBeNull();

        $payload = json_decode((string) $controller->index(Request::create('/api/v1/skills'))->getContent(), true);

        expect(array_column($payload['data']['skills'], 'name'))->toContain('git');
        // The row is materialised rather than the lookup skipped.
        expect(
            Illuminate\Database\Capsule\Manager::table('principals')
                ->where('type', 'user')->where('user_id', $userId)->value('id'),
        )->not->toBeNull();
    } finally {
        array_map('unlink', glob($root . '/git/*') ?: []);
        @rmdir($root . '/git');
        @rmdir($root);
    }
});

/** `GET /skills/{slug}/files/{path}` — the read, and that it cannot be turned into a
 *  read of what the caller is not entitled to. */
describe('GET /skills/{slug}/files/{path}', function (): void {

    it('returns a sidecar listed by the detail endpoint', function (): void {
        $userId = skillCtrlUser('file-own@example.com');
        $GLOBALS['__skillCtrlUserId'] = $userId;
        [$controller, $cleanup, $root] = makeSkillControllerFixture();
        try {
            writeToySkillMd($root, 'git', '# Body', 'Git skill.');
            $dir = $root . '/git';
            mkdir($dir . '/templates', 0o755, true);
            file_put_contents($dir . '/templates/report.typ', '#let title = "Report"');

            $show = Request::create('/api/v1/skills/git');
            $show->attributes->set('slug', 'git');
            $detail = json_decode((string) $controller->show($show)->getContent(), true);
            expect(array_column($detail['data']['skill']['files'], 'path'))
                ->toContain('templates/report.typ');

            $request = Request::create('/api/v1/skills/git/files/templates/report.typ');
            $request->attributes->set('slug', 'git');
            $request->attributes->set('path', 'templates/report.typ');
            $response = $controller->file($request);
            $payload = json_decode((string) $response->getContent(), true);

            expect($response->getStatusCode())->toBe(200)
                ->and($payload['data']['path'])->toBe('templates/report.typ')
                ->and($payload['data']['content'])->toBe('#let title = "Report"')
                ->and($payload['data']['bytes'])->toBe(21);
        } finally {
            $cleanup();
        }
    });

    it('refuses a path the skill does not contain', function (): void {
        $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('file-missing@example.com');
        [$controller, $cleanup, $root] = makeSkillControllerFixture();
        try {
            writeToySkillMd($root, 'git', '# Body', 'Git skill.');

            $request = Request::create('/api/v1/skills/git/files/nope.txt');
            $request->attributes->set('slug', 'git');
            $request->attributes->set('path', 'nope.txt');
            $response = $controller->file($request);

            expect($response->getStatusCode())->toBe(404)
                ->and(json_decode((string) $response->getContent(), true)['error']['code'])
                ->toBe('SKILL_FILE_NOT_FOUND');
        } finally {
            $cleanup();
        }
    });

    it('answers 404 for a traversal path', function (): void {
        // A traversal that succeeded would read anything the process can open.
        $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('file-traversal@example.com');
        [$controller, $cleanup, $root] = makeSkillControllerFixture();
        try {
            writeToySkillMd($root, 'git', '# Body', 'Git skill.');
            file_put_contents($root . '/secret.txt', 'TOP SECRET');

            foreach (['../secret.txt', 'templates/../../secret.txt', '/etc/passwd'] as $path) {
                $request = Request::create('/api/v1/skills/git/files/x');
                $request->attributes->set('slug', 'git');
                $request->attributes->set('path', $path);
                $response = $controller->file($request);

                expect($response->getStatusCode())->toBe(404)
                    ->and((string) $response->getContent())->not->toContain('TOP SECRET');
            }
        } finally {
            $cleanup();
        }
    });

    it('answers 404 for another principal\'s skill rather than 403', function (): void {
        $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('file-cross@example.com');
        [$controller, $cleanup] = makeSkillControllerFixture();
        try {
            $request = Request::create('/api/v1/skills/team-playbook/files/SKILL.md');
            $request->attributes->set('slug', 'team-playbook');
            $request->attributes->set('path', 'SKILL.md');
            $response = $controller->file($request);

            expect($response->getStatusCode())->toBe(404)
                ->and(json_decode((string) $response->getContent(), true)['error']['code'])
                ->toBe('SKILL_FILE_NOT_FOUND');

            // A refusal only means something if the same read works for an own skill.
            $own = Request::create('/api/v1/skills/my-notes/files/SKILL.md');
            $own->attributes->set('slug', 'my-notes');
            $own->attributes->set('path', 'SKILL.md');
            expect($controller->file($own)->getStatusCode())->toBe(200);
        } finally {
            $cleanup();
        }
    });

    it('answers 404 when the skill itself does not exist', function (): void {
        $GLOBALS['__skillCtrlUserId'] = skillCtrlUser('file-noskill@example.com');
        [$controller, $cleanup] = makeSkillControllerFixture();
        try {
            $request = Request::create('/api/v1/skills/nope/files/SKILL.md');
            $request->attributes->set('slug', 'nope');
            $request->attributes->set('path', 'SKILL.md');

            expect($controller->file($request)->getStatusCode())->toBe(404);
        } finally {
            $cleanup();
        }
    });

    it('refuses a file over the cap even if a provider returns it anyway', function (): void {
        $oversized = str_repeat('a', SkillProviderInterface::MAX_FILE_BYTES + 1);
        $rogue = Mockery::mock(SkillProviderInterface::class);
        $rogue->shouldReceive('source')->andReturn('rogue');
        $rogue->shouldReceive('getSkills')->andReturn([]);
        $rogue->shouldReceive('getSkillDetails')->andReturnNull();
        // The registry picks the owner by asking for the *listing* first, so a mock
        // answering null there is never asked for the file at all.
        $rogue->shouldReceive('getSkillFiles')->andReturn([['path' => 'huge.txt', 'bytes' => 1_000_000]]);
        $rogue->shouldReceive('getSkillFile')->andReturn($oversized);

        $userId = skillCtrlUser('file-cap@example.com');
        $auth = Mockery::mock(AuthService::class);
        $auth->shouldReceive('currentUserId')->andReturn($userId);
        $controller = new SkillController(
            $auth,
            new SkillProviderRegistry([$rogue]),
            new PrincipalService(new PrincipalResolver()),
        );

        $request = Request::create('/api/v1/skills/big/files/huge.txt');
        $request->attributes->set('slug', 'big');
        $request->attributes->set('path', 'huge.txt');
        $response = $controller->file($request);

        expect($response->getStatusCode())->toBe(404)
            ->and((string) $response->getContent())->not->toContain('aaaa');
    });

    it('requires authentication', function (): void {
        $auth = Mockery::mock(AuthService::class);
        $auth->shouldReceive('currentUserId')->andReturn(null);
        [$controller, $cleanup] = makeSkillControllerFixture($auth);
        try {
            $request = Request::create('/api/v1/skills/git/files/SKILL.md');
            $request->attributes->set('slug', 'git');
            $request->attributes->set('path', 'SKILL.md');
            $response = $controller->file($request);

            expect($response->getStatusCode())->toBe(401)
                ->and(json_decode((string) $response->getContent(), true)['error']['code'])
                ->toBe('UNAUTHENTICATED');
        } finally {
            $cleanup();
        }
    });
});
