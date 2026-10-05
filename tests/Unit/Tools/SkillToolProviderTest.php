<?php

declare(strict_types=1);

use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\SkillTool;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * `SkillTool` is the only place both authorisation gates meet: the agent's
 * `allowed_skills` list, and whether the execution's principal can see the
 * skill at all. Neither alone is sufficient — an allowlisted name from another
 * tenant is exactly the case that must fail, and it fails silently if the tool
 * only checks the list.
 *
 * It is also the boundary where untrusted content enters the model's context,
 * so the membership check and the size cap are asserted from the caller's side
 * against a provider that misbehaves on purpose.
 */
function makeProviderSkillTool(
    SkillProviderRegistry $registry,
    array $allowed = ['my-skill'],
    ?PrincipalResolver $principals = null,
): SkillTool {
    $config = Mockery::mock(ToolConfigServiceInterface::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['allowed_skills' => $allowed]);

    return new SkillTool($registry, $config, $principals ?? new PrincipalResolver());
}

function providerContext(int $principalId): PrincipalContext
{
    return new PrincipalContext($principalId, 'user', $principalId, $principalId);
}

describe('the principal gate', function (): void {
    it('reads a skill the principal owns', function (): void {
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.', null, 10);
        $provider->onlyVisibleTo = 10;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(
            ['action' => 'read', 'name' => 'my-skill'],
            1,
            null,
            providerContext(10),
        );

        // `read` returns file *content*; the descriptor's body is a separate
        // path, reached by the admin detail endpoint.
        expect($result->success)->toBeTrue()
            ->and($result->content)->toBe('content of SKILL.md');
    });

    it('refuses the same skill for a different principal even when allowlisted', function (): void {
        // The whole point: `allowed_skills` is a *list*, not a grant. Without
        // the second gate this is a cross-tenant read.
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Tenant A secret.', null, 10);
        $provider->onlyVisibleTo = 10;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(
            ['action' => 'read', 'name' => 'my-skill'],
            1,
            null,
            providerContext(11),
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not available')
            ->and($result->content)->not->toContain('Tenant A secret');
    });

    it('refuses everything when the principal cannot be resolved', function (): void {
        // A null context with a provider that would otherwise answer for
        // principal 10 must not fall back to "everyone".
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.', null, 10);
        $provider->onlyVisibleTo = 10;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(['action' => 'read', 'name' => 'my-skill'], 1, 1);

        expect($result->success)->toBeFalse();
    });

    it('refuses everything for the principalId-0 sentinel', function (): void {
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.');
        $provider->onlyVisibleTo = 0;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        // principalId 0 is one of the two unresolvable sentinels; the other is a
        // dangling non-zero id, which no structural check can catch.
        $result = $tool->execute(
            ['action' => 'read', 'name' => 'my-skill'],
            1,
            null,
            providerContext(0),
        );

        expect($result->success)->toBeFalse();
    });

    it('never resolves the principal from the runner user id', function (): void {
        // `$userId` is whoever clicked. A group agent triggered by one member
        // must not read that member's personal skills; the agent's own
        // principal is the scope, which is also what makes a scheduled run
        // work at all.
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Runner personal.');
        $provider->onlyVisibleTo = 77;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(['action' => 'read', 'name' => 'my-skill'], 1, 77);

        expect($result->success)->toBeFalse();
    });

    it('falls back to the agent principal when there is no context', function (): void {
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.', null, 10);
        $provider->onlyVisibleTo = 10;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        // Agent 1 does not exist, so the resolver returns the 0 sentinel and
        // the read is refused. The assertion is that the tool asked the
        // resolver at all rather than reading `$userId`.
        expect($tool->execute(['action' => 'read', 'name' => 'my-skill'], 1, 10)->success)->toBeFalse();
    });

    it('still serves principal-independent skills to every principal', function (): void {
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.');
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        expect($tool->execute(
            ['action' => 'read', 'name' => 'my-skill'],
            1,
            null,
            providerContext(999),
        )->success)->toBeTrue();
    });
});

describe('the membership check', function (): void {
    it('rejects a path the provider invents in its read but not in its listing', function (): void {
        // A membership check the caller cannot enforce on the callee is not a
        // check. The provider is deliberately buggy here: it will happily
        // return content for a file it never listed.
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.');
        $provider->answerUnlistedPaths = true;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(
            ['action' => 'read', 'name' => 'my-skill', 'filename' => 'etc/passwd'],
            1,
            null,
            providerContext(10),
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not part of skill')
            ->and($result->content)->not->toContain('leaked');
    });

    it('rejects traversal before the provider is consulted', function (): void {
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.');
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        foreach (['../SKILL.md', '/etc/passwd', "SKILL.md\0.png", './././x'] as $filename) {
            $result = $tool->execute(
                ['action' => 'read', 'name' => 'my-skill', 'filename' => $filename],
                1,
                null,
                providerContext(10),
            );
            expect($result->success)->toBeFalse();
        }
    });

    it('reports an unlisted path as absent from the skill', function (): void {
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['other.md'], 'Body.');
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(
            ['action' => 'read', 'name' => 'my-skill', 'filename' => 'SKILL.md'],
            1,
            1,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not part of skill');
    });

    it('names the size when a listed file is over the cap', function (): void {
        // The provider enforces the cap and returns null; the listing still
        // knows the size, which is what separates "too big" from "unreadable".
        // Reporting "not part of skill" here would send the model looking for
        // a different path for a file it can plainly see in the listing.
        $provider = (new StubSkillProvider('custom'))->add(
            'my-skill',
            ['SKILL.md'],
            'Body.',
            null,
            null,
            ['SKILL.md' => 60_000],
        );
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(['action' => 'read', 'name' => 'my-skill'], 1, 1);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('is 60000 bytes')
            ->and($result->content)->toContain('capped at 50000 bytes')
            ->and($result->content)->not->toContain('not part of skill');
    });
});

describe('the size cap', function (): void {
    it('rejects content a buggy provider returns over the cap', function (): void {
        // The listing advertises a 4-byte file; the read returns 50 KB + 1.
        // A provider that skips the cap it is contractually required to
        // enforce must not be able to spend the model's context window.
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.');
        $provider->ignoreSizeCap = true;
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

        $result = $tool->execute(['action' => 'read', 'name' => 'my-skill'], 1, 1);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('capped at 50000 bytes');
    });

    it('names the cap constant it enforces', function (): void {
        expect(SkillProviderInterface::MAX_FILE_BYTES)->toBe(50_000);
    });
});

describe('the allowlist gate is unchanged', function (): void {
    it('rejects a skill the agent has not allowlisted', function (): void {
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.');
        $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]), ['other-skill']);

        $result = $tool->execute(['action' => 'read', 'name' => 'my-skill'], 1, 1);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not in the allowed_skills list');
    });

    it('requires a name', function (): void {
        $tool = makeProviderSkillTool(new SkillProviderRegistry([new StubSkillProvider('custom')]));

        expect($tool->execute(['action' => 'read', 'name' => '  '], 1, 1)->content)->toBe('name is required.');
    });
});

it('lists files through the provider for a visible skill', function (): void {
    $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md', 'a.md'], 'Body.', null, 10);
    $provider->onlyVisibleTo = 10;
    $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]));

    $result = $tool->execute(
        ['action' => 'files', 'name' => 'my-skill'],
        1,
        null,
        providerContext(10),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['files'])->toHaveCount(2)
        ->and($result->content)->toContain('SKILL.md', 'a.md');
});

it('reports a fileless skill as empty rather than failing', function (): void {
    $provider = (new StubSkillProvider('custom'))->add('empty', [], 'Body.');
    $tool = makeProviderSkillTool(new SkillProviderRegistry([$provider]), ['empty']);

    $result = $tool->execute(['action' => 'files', 'name' => 'empty'], 1, 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('has no files listed');
});

describe('gate 1 and gate 2 resolve the same principal', function (): void {
    it('reads the allowlist through the execution context, not the runner', function (): void {
        $principalId = 10;
        $provider = (new StubSkillProvider('custom'))->add('my-skill', ['SKILL.md'], 'Body.', null, $principalId);
        $provider->onlyVisibleTo = $principalId;

        // The allowlist is stored against the agent's principal. A cascade
        // resolved from the runner instead would not find it — a group agent's
        // group-level `allowed_skills`, or any scheduled run with no runner.
        $seenContext = null;
        $config = Mockery::mock(ToolConfigServiceInterface::class);
        $config->shouldReceive('getEffectiveSettings')
            ->andReturnUsing(function (string $class, int $agentId, ?int $userId, ?PrincipalContext $context = null) use (&$seenContext, $principalId): array {
                $seenContext = $context;

                return $context?->principalId === $principalId
                    ? ['allowed_skills' => ['my-skill']]
                    : ['allowed_skills' => []];
            });

        $tool = new SkillTool(
            new SkillProviderRegistry([$provider]),
            $config,
            new PrincipalResolver(),
        );

        // A runner who is *not* the owner — the divergence a runner-scoped
        // cascade produces.
        $result = $tool->execute(
            ['action' => 'read', 'name' => 'my-skill'],
            1,
            null,
            providerContext($principalId),
        );

        expect($seenContext)->toBeInstanceOf(PrincipalContext::class)
            ->and($seenContext->principalId)->toBe($principalId)
            ->and($result->success)->toBeTrue()
            ->and($result->content)->toContain('content of SKILL.md');
    });
});
