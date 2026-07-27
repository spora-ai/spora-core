<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Http\SkillController;
use Spora\Skills\SkillScanner;

function makeSkillControllerFixture(): array
{
    $root = sys_get_temp_dir() . '/spora_skill_ctrl_' . uniqid('', true);
    mkdir($root, 0o755, true);
    $scanner = new SkillScanner([
        ['path' => $root, 'source' => 'project'],
    ]);

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn(1);

    $controller = new SkillController($auth, $scanner);

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

    return [$controller, $cleanup, $root];
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

test('GET /skills returns summaries for every discovered skill', function (): void {
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', '# Body', 'Git skill.');
        writeToySkillMd($root, 'pdf', '# Body', 'PDF skill.');

        $response = $controller->index();
        $payload = json_decode((string) $response->getContent(), true);

        expect($response->getStatusCode())->toBe(200)
            ->and($payload['data']['skills'])->toHaveCount(2)
            ->and(array_column($payload['data']['skills'], 'name'))->toContain('git', 'pdf')
            ->and($payload['data']['skills'][0]['description'])->toBeString();
    } finally {
        $cleanup();
    }
});

test('GET /skills requires authentication', function (): void {
    $root = sys_get_temp_dir() . '/spora_skill_ctrl_' . uniqid('', true);
    mkdir($root, 0o755, true);
    $scanner = new SkillScanner([['path' => $root, 'source' => 'project']]);

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn(null);

    $controller = new SkillController($auth, $scanner);
    $response = $controller->index();

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode((string) $response->getContent(), true)['error']['code'])
        ->toBe('UNAUTHENTICATED');

    @rmdir($root);
});

test('GET /skills/{slug} returns the skill detail', function (): void {
    [$controller, $cleanup, $root] = makeSkillControllerFixture();
    try {
        writeToySkillMd($root, 'git', "# Body\n\nSteps here.", 'Git skill.');

        $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/skills/git');
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
    [$controller, $cleanup] = makeSkillControllerFixture();
    try {
        $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/skills/missing');
        $request->attributes->set('slug', 'missing');

        $response = $controller->show($request);
        $payload = json_decode((string) $response->getContent(), true);

        expect($response->getStatusCode())->toBe(404)
            ->and($payload['error']['code'])->toBe('SKILL_NOT_FOUND');
    } finally {
        $cleanup();
    }
});
