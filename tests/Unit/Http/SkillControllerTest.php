<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Http\SkillController;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Skills\Providers\FilesystemSkillProvider;
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

function writeToySkillMd(string $root, string $slug, string $body, string $description = 'Test skill.'): void
{
    $dir = $root . '/' . $slug;
    mkdir($dir, 0o755, true);
    file_put_contents(
        $dir . '/SKILL.md',
        "---\nname: {$slug}\ndescription: {$description}\n---\n\n" . ltrim($body, "\n"),
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
        // A principal that owns nothing, but that the caller can name.
        $request = Request::create('/api/v1/skills/my-notes', 'GET', ['principal_id' => $ownPrincipal + 1000]);
        $request->attributes->set('slug', 'my-notes');

        $response = $controller->show($request);

        // 403 would confirm the skill exists — a cross-tenant existence oracle.
        expect($response->getStatusCode())->toBe(404)
            ->and(json_decode((string) $response->getContent(), true)['error']['code'])
            ->toBe('SKILL_NOT_FOUND');
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
